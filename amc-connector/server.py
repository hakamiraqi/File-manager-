#!/usr/bin/env python3
"""
AMC connector: a small HTTP API over the Auto Multiple Choice command line.

Every request carries `Authorization: Bearer <AMC_TOKEN>`. One AMC project per exam, in
$AMC_DATA/<project>/:

  GET    /health                          AMC's version (no token needed)
  PUT    /projects/<p>                     {"source": AMC-TXT, "students": CSV, "pictures": {name: base64}} → builds the sheets
  GET    /projects/<p>/subject.pdf         the sheets to print (one named sheet per student)
  GET    /projects/<p>/correction.pdf      the corrected sheet
  POST   /projects/<p>/scans?name=<file>   a scan (PDF or picture) as the request body
  DELETE /projects/<p>/scans               forget the scans (and their reading)
  POST   /projects/<p>/process             read the scans, mark, match students, export (in the background)
  GET    /projects/<p>/status              {"state": idle|building|processing|ready|failed, ...}
  GET    /projects/<p>/results.csv         the results, with the ticked boxes as letters
  DELETE /projects/<p>                     the whole project

Only the standard library: the image stays the Debian AMC package and Python.
"""
import base64
import json, os, re, shutil, subprocess, threading, time, hmac
from http.server import ThreadingHTTPServer, BaseHTTPRequestHandler
from urllib.parse import urlparse, parse_qs

DATA = os.environ.get('AMC_DATA', '/data')
TOKEN = os.environ.get('AMC_TOKEN', '')
PORT = int(os.environ.get('AMC_PORT', '8080'))
MAX_UPLOAD = int(os.environ.get('AMC_MAX_UPLOAD_MB', '200')) * 1024 * 1024
NAME = re.compile(r'^[A-Za-z0-9_-]{1,64}$')
FILE = re.compile(r'^[A-Za-z0-9._-]{1,120}\.(pdf|png|jpg|jpeg|tif|tiff)$', re.I)
locks = {}


def amc(*args, cwd, log, timeout=3600):
    """Runs one AMC command, appending its output to the project's log."""
    cmd = ['auto-multiple-choice', *args]
    with open(log, 'a', encoding='utf-8') as f:
        f.write('\n$ ' + ' '.join(cmd) + '\n')
        f.flush()
        r = subprocess.run(cmd, cwd=cwd, stdout=f, stderr=subprocess.STDOUT, timeout=timeout)
    if r.returncode != 0:
        raise RuntimeError(f'{args[0]} failed ({r.returncode})')


def status(p, **update):
    path = os.path.join(DATA, p, 'status.json')
    try:
        s = json.load(open(path))
    except Exception:
        s = {'state': 'idle'}
    if update:
        s.update(update, updated_at=time.strftime('%Y-%m-%dT%H:%M:%S%z'))
        json.dump(s, open(path, 'w'))
    return s


def tail(p, n=40):
    try:
        return ''.join(open(os.path.join(DATA, p, 'amc.log'), encoding='utf-8', errors='replace').readlines()[-n:])
    except Exception:
        return ''


def build(p, source, students, pictures=None):
    d = os.path.join(DATA, p)
    os.makedirs(os.path.join(d, 'data'), exist_ok=True)
    # The questions' and choices' pictures (2026-11-09), named in the source as img/<name>.
    img = os.path.join(d, 'img')
    if os.path.isdir(img):
        shutil.rmtree(img)
    os.makedirs(img, exist_ok=True)
    for name, data in (pictures or {}).items():
        if re.fullmatch(r'[a-f0-9]{64}\.(png|jpg)', name):
            with open(os.path.join(img, name), 'wb') as f:
                f.write(base64.b64decode(data))
    os.makedirs(os.path.join(d, 'scans'), exist_ok=True)
    log = os.path.join(d, 'amc.log')
    open(log, 'w').close()
    open(os.path.join(d, 'source.txt'), 'w', encoding='utf-8').write(source)
    open(os.path.join(d, 'students.csv'), 'w', encoding='utf-8').write(students)
    engine = 'xelatex' if re.search(r'^Lang:\s*AR\b', source, re.M) else 'pdflatex'
    status(p, state='building', step='prepare', error=None)
    try:
        common = ['--filter', 'plain', '--with', engine, '--prefix', d + '/', '--data', d + '/data', d + '/source.txt']
        amc('prepare', '--mode', 's', '--out-sujet', d + '/subject.pdf', '--out-corrige', d + '/correction.pdf',
            '--out-calage', d + '/calage.xy', *common, cwd=d, log=log)
        status(p, step='layout')
        amc('meptex', '--src', d + '/calage.xy', '--data', d + '/data', cwd=d, log=log)
        status(p, step='scoring')
        amc('prepare', '--mode', 'b', *common, cwd=d, log=log)
        if engine == 'xelatex':
            # Draw the letters as shapes, so every PDF viewer (phones too) shows the sheet exactly as
            # it prints; some viewers redraw Arabic sheets' text in their own fonts and mangle it.
            status(p, step='outline')
            for name in ('subject.pdf', 'correction.pdf'):
                src, tmp = os.path.join(d, name), os.path.join(d, name + '.tmp')
                r = subprocess.run(['gs', '-q', '-dSAFER', '-dBATCH', '-dNOPAUSE', '-dNoOutputFonts', '-sDEVICE=pdfwrite',
                                    '-o', tmp, src], capture_output=True, text=True, timeout=900)
                if r.returncode == 0 and os.path.getsize(tmp) > 0:
                    os.replace(tmp, src)
                elif os.path.exists(tmp):
                    os.remove(tmp)
        pages = subprocess.run(['pdfinfo', d + '/subject.pdf'], capture_output=True, text=True).stdout
        m = re.search(r'Pages:\s+(\d+)', pages)
        status(p, state='built', step=None, pages=int(m.group(1)) if m else None, built_at=time.time())
    except Exception as e:
        status(p, state='failed', step=status(p).get('step'), error=str(e), log=tail(p))


def process(p):
    d = os.path.join(DATA, p)
    log = os.path.join(d, 'amc.log')
    status(p, state='processing', step='images', error=None)
    try:
        scans = sorted(f for f in os.listdir(d + '/scans') if FILE.match(f))
        if not scans:
            raise RuntimeError('no scans')
        # PDFs into page pictures; pictures as they are.
        images = os.path.join(d, 'images')
        shutil.rmtree(images, ignore_errors=True)
        os.makedirs(images)
        listfile = os.path.join(d, 'images.txt')
        with open(d + '/scans.txt', 'w') as f:
            f.write('\n'.join(os.path.join(d, 'scans', s) for s in scans) + '\n')
        amc('getimages', '--copy-to', images, '--list', listfile, '--vector-density', '250', '--orientation', 'portrait',
            *[os.path.join(d, 'scans', s) for s in scans], cwd=d, log=log)
        status(p, step='analyse')
        amc('analyse', '--projet', d + '/', '--data', d + '/data', '--liste-fichiers', listfile, cwd=d, log=log, timeout=7200)
        # 0.3: an empty box with a dark Arabic letter in it (ج) is about 0.15 black; a filled one 0.6–0.9.
        status(p, step='note')
        amc('note', '--data', d + '/data', '--seuil', os.environ.get('AMC_THRESHOLD', '0.3'), cwd=d, log=log)
        status(p, step='association')
        amc('association-auto', '--data', d + '/data', '--pre-association', '--liste', d + '/students.csv', '--liste-key', 'id', cwd=d, log=log)
        status(p, step='export')
        amc('export', '--data', d + '/data', '--module', 'CSV', '--fich-noms', d + '/students.csv', '--useall', '0', '--sort', 'l',
            '--option-out', 'ticked=AB', '--option-out', 'columns=student.copy,student.key,id,name', '--o', d + '/results.csv', cwd=d, log=log)
        rows = max(0, sum(1 for _ in open(d + '/results.csv', encoding='utf-8', errors='replace')) - 1)
        status(p, state='ready', step=None, pages=status(p).get('pages'), scans=len(scans), rows=rows, processed_at=time.time())
    except Exception as e:
        status(p, state='failed', error=str(e), log=tail(p))


def version():
    try:
        out = subprocess.run(['dpkg-query', '-W', '-f', '${Version}', 'auto-multiple-choice'], capture_output=True, text=True, timeout=20).stdout.strip()
        return out or 'unknown'
    except Exception as e:
        return 'unavailable: ' + str(e)


class Handler(BaseHTTPRequestHandler):
    server_version = 'amc-connector/1'

    def log_message(self, fmt, *args):
        pass

    def send(self, code, body=b'', ctype='application/json'):
        if isinstance(body, (dict, list)):
            body = json.dumps(body, ensure_ascii=False).encode()
        self.send_response(code)
        self.send_header('Content-Type', ctype)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def authorized(self):
        given = self.headers.get('Authorization', '')
        return bool(TOKEN) and hmac.compare_digest(given, 'Bearer ' + TOKEN)

    def route(self, method):
        url = urlparse(self.path)
        parts = [x for x in url.path.split('/') if x]
        if method == 'GET' and parts == ['health']:
            return self.send(200, {'ok': True, 'amc': version(), 'token_set': bool(TOKEN)})
        if not self.authorized():
            return self.send(401, {'error': 'unauthorized'})
        if len(parts) < 2 or parts[0] != 'projects' or not NAME.match(parts[1]):
            return self.send(404, {'error': 'not found'})
        p = parts[1]
        d = os.path.join(DATA, p)
        rest = parts[2:]
        lock = locks.setdefault(p, threading.Lock())

        if method == 'PUT' and not rest:
            body = json.loads(self.read() or b'{}')
            if not isinstance(body.get('source'), str) or not isinstance(body.get('students'), str):
                return self.send(422, {'error': 'source and students are needed'})
            if not lock.acquire(blocking=False):
                return self.send(409, {'error': 'busy'})
            try:
                if os.path.isdir(d + '/data'):
                    shutil.rmtree(d + '/data')
                build(p, body['source'], body['students'], body.get('pictures') if isinstance(body.get('pictures'), dict) else None)
            finally:
                lock.release()
            s = status(p)
            return self.send(200 if s.get('state') == 'built' else 500, s)
        if not os.path.isdir(d):
            return self.send(404, {'error': 'no such project'})
        if method == 'DELETE' and not rest:
            shutil.rmtree(d, ignore_errors=True)
            return self.send(200, {'ok': True})
        if method == 'GET' and rest in (['subject.pdf'], ['correction.pdf'], ['results.csv']):
            f = os.path.join(d, rest[0])
            if not os.path.isfile(f):
                return self.send(404, {'error': 'not ready'})
            return self.send(200, open(f, 'rb').read(), 'text/csv; charset=utf-8' if rest[0].endswith('.csv') else 'application/pdf')
        if method == 'GET' and rest == ['status']:
            s = status(p)
            s['scans'] = len([f for f in os.listdir(d + '/scans') if FILE.match(f)]) if os.path.isdir(d + '/scans') else 0
            return self.send(200, s)
        if method == 'POST' and rest == ['scans']:
            name = (parse_qs(url.query).get('name') or [''])[0]
            name = re.sub(r'[^A-Za-z0-9._-]', '_', name)
            if not FILE.match(name):
                return self.send(422, {'error': 'a PDF or a picture'})
            data = self.read()
            os.makedirs(d + '/scans', exist_ok=True)
            open(os.path.join(d, 'scans', f'{int(time.time() * 1000)}-{name}'), 'wb').write(data)
            return self.send(200, {'ok': True, 'bytes': len(data)})
        if method == 'DELETE' and rest == ['scans']:
            shutil.rmtree(d + '/scans', ignore_errors=True)
            os.makedirs(d + '/scans')
            for f in ('capture.sqlite', 'scoring.sqlite', 'association.sqlite'):
                try:
                    os.remove(os.path.join(d, 'data', f))
                except FileNotFoundError:
                    pass
            for f in ('results.csv',):
                try:
                    os.remove(os.path.join(d, f))
                except FileNotFoundError:
                    pass
            status(p, state='built', error=None)
            return self.send(200, {'ok': True})
        if method == 'POST' and rest == ['process']:
            if status(p).get('state') not in ('built', 'ready', 'failed'):
                return self.send(409, {'error': 'build the sheets first'})
            if not lock.acquire(blocking=False):
                return self.send(409, {'error': 'busy'})

            def run():
                try:
                    process(p)
                finally:
                    lock.release()
            threading.Thread(target=run, daemon=True).start()
            return self.send(202, {'ok': True})
        return self.send(404, {'error': 'not found'})

    def read(self):
        n = int(self.headers.get('Content-Length') or 0)
        if n > MAX_UPLOAD:
            raise ValueError('too big')
        return self.rfile.read(n) if n else b''

    def handle_method(self, m):
        try:
            self.route(m)
        except ValueError as e:
            self.send(413, {'error': str(e)})
        except Exception as e:
            self.send(500, {'error': str(e)})

    def do_GET(self): self.handle_method('GET')
    def do_PUT(self): self.handle_method('PUT')
    def do_POST(self): self.handle_method('POST')
    def do_DELETE(self): self.handle_method('DELETE')


if __name__ == '__main__':
    if not TOKEN:
        print('AMC_TOKEN is not set: every request but /health will be refused.')
    os.makedirs(DATA, exist_ok=True)
    print(f'AMC connector on :{PORT}, data in {DATA}')
    ThreadingHTTPServer(('0.0.0.0', PORT), Handler).serve_forever()
