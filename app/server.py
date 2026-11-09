"""Health-centre family-planning readiness survey: a shared online version of the Excel form.

Anyone with the link can add a centre's form and edit any form. Every save keeps a revision, so
a wrong or malicious edit can be put back; deleted forms go to a bin and can be restored.
The questions come from template.xlsx itself (the original Excel form), and every download is
that same file filled in, so the website, the Excel form and the exports never drift apart.

Python standard library + openpyxl; data in SQLite under $SURVEY_DATA.
"""
import contextlib
import hashlib
import io
import json
import os
import re
import sqlite3
import threading
import time
from datetime import datetime, timezone, timedelta
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs, quote

import openpyxl

HERE = os.path.dirname(os.path.abspath(__file__))
DATA = os.environ.get('SURVEY_DATA', os.path.join(HERE, 'data'))
TEMPLATE = os.path.join(HERE, 'template.xlsx')
PORT = int(os.environ.get('SURVEY_PORT', '8080'))
BAGHDAD = timezone(timedelta(hours=3))
YES_NO = ('', 'نعم', 'كلا')
MAX_BODY = 512 * 1024


# ---------------------------------------------------------------- the form, read from the Excel file
def read_schema(path=TEMPLATE):
    """Sections, their columns and items, and the header fields, with their cells in the Excel form."""
    ws = openpyxl.load_workbook(path).worksheets[0]
    merged = {}
    for rng in ws.merged_cells.ranges:
        merged[(rng.min_row, rng.min_col)] = rng
    text = lambda v: re.sub(r'\s+', ' ', str(v)).strip() if v is not None else ''
    title = text(ws['A2'].value)
    header = []
    header_row = None
    sections = []
    r = 1
    while r <= ws.max_row:
        a = ws.cell(r, 1).value
        rng = merged.get((r, 1))
        if rng is not None and rng.max_col >= 8 and r > 2:          # a section title across the sheet
            cols_row = r + 1
            cols = []
            for c in range(3, 9):
                label = text(ws.cell(cols_row, c).value)
                if not label:
                    continue
                span = merged.get((cols_row, c))
                kind = ('yesno' if 'نعم' in label else 'text' if ('ملاحظات' in label or 'المعوقات' in label)
                        else 'number' if re.search('عدد|الكمية|الغرف', label) else 'short')
                cols.append({'col': openpyxl.utils.get_column_letter(c), 'label': label, 'kind': kind,
                             'span': (span.max_col - span.min_col + 1) if span else 1})
            items = []
            rr = cols_row + 1
            while rr <= ws.max_row and isinstance(ws.cell(rr, 1).value, (int, float)):
                items.append({'row': rr, 'no': int(ws.cell(rr, 1).value), 'label': text(ws.cell(rr, 2).value)})
                rr += 1
            sections.append({'title': text(a), 'labels': [text(ws.cell(cols_row, 1).value), text(ws.cell(cols_row, 2).value)],
                             'cols': cols, 'items': items})
            r = rr
            continue
        if header_row is None and r > 2 and text(ws.cell(r, 2).value) and rng is None and not isinstance(a, (int, float)):
            header_row = r
            for c in range(2, 9):
                label = text(ws.cell(r, c).value)
                if label:
                    header.append({'col': openpyxl.utils.get_column_letter(c), 'label': label, 'kind': 'date' if 'تاريخ' in label else 'short'})
        r += 1
    return {'title': title, 'header': header, 'header_row': header_row + 1, 'sections': sections}


SCHEMA = read_schema()
CELLS = {}                                   # (row, col) -> kind: every cell a form may fill
for f in SCHEMA['header']:
    CELLS[(SCHEMA['header_row'], f['col'])] = f['kind']
for s in SCHEMA['sections']:
    for it in s['items']:
        for c in s['cols']:
            CELLS[(it['row'], c['col'])] = c['kind']
HEADER_COLS = [f['col'] for f in SCHEMA['header']]


def clean(values):
    """Only the form's own cells, as short strings: {"r7": {"C": "نعم"}}."""
    out = {}
    if not isinstance(values, dict):
        return out
    for rk, row in values.items():
        m = re.fullmatch(r'r(\d{1,3})', str(rk))
        if not m or not isinstance(row, dict):
            continue
        for col, v in row.items():
            kind = CELLS.get((int(m.group(1)), str(col)))
            if kind is None or v is None:
                continue
            v = str(v).strip()[:2000 if kind == 'text' else 120]
            if kind == 'yesno' and v not in YES_NO:
                continue
            if kind == 'date' and v and not re.fullmatch(r'\d{4}-\d{2}-\d{2}', v):
                continue
            if v:
                out.setdefault(rk, {})[col] = v
    return out


def summary(values):
    h = values.get('r%d' % SCHEMA['header_row'], {})
    return {f['col']: h.get(f['col'], '') for f in SCHEMA['header']}


def filled(values):
    rows = [it['row'] for s in SCHEMA['sections'] for it in s['items']]
    return sum(1 for r in rows if values.get('r%d' % r, {}).get('C'))


# ---------------------------------------------------------------- storage
os.makedirs(DATA, exist_ok=True)
DB_PATH = os.path.join(DATA, 'survey.sqlite')
LOCK = threading.Lock()


def connect():
    con = sqlite3.connect(DB_PATH, timeout=30)
    con.row_factory = sqlite3.Row
    con.execute('PRAGMA journal_mode=WAL')
    con.execute('PRAGMA foreign_keys=ON')
    return con


@contextlib.contextmanager
def db():
    """A connection for one request: committed when the block ends without an error, always closed."""
    con = connect()
    try:
        with con:
            yield con
    finally:
        con.close()


with db() as con:
    con.executescript('''
    CREATE TABLE IF NOT EXISTS forms (id INTEGER PRIMARY KEY, data TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL, updated_at TEXT NOT NULL, deleted_at TEXT);
    CREATE TABLE IF NOT EXISTS revisions (id INTEGER PRIMARY KEY, form_id INTEGER NOT NULL REFERENCES forms(id),
        data TEXT NOT NULL, saved_at TEXT NOT NULL, who TEXT NOT NULL, note TEXT NOT NULL DEFAULT '');
    CREATE INDEX IF NOT EXISTS revisions_form ON revisions(form_id, id);
    ''')


def now():
    return datetime.now(BAGHDAD).isoformat(timespec='seconds')


def form_out(row, values=None):
    values = json.loads(row['data']) if values is None else values
    return {'id': row['id'], 'version': row['version'], 'values': values, 'created_at': row['created_at'],
            'updated_at': row['updated_at'], 'deleted_at': row['deleted_at'], 'header': summary(values), 'filled': filled(values)}


def remember(con, form_id, values, who, note=''):
    """A revision per save; quick saves by the same person within two minutes count as one."""
    last = con.execute('SELECT id, saved_at, who, note FROM revisions WHERE form_id=? ORDER BY id DESC LIMIT 1', (form_id,)).fetchone()
    data = json.dumps(values, ensure_ascii=False)
    if last and not note and not last['note'] and last['who'] == who and \
            datetime.fromisoformat(last['saved_at']) > datetime.now(BAGHDAD) - timedelta(minutes=2):
        con.execute('UPDATE revisions SET data=?, saved_at=? WHERE id=?', (data, now(), last['id']))
    else:
        con.execute('INSERT INTO revisions (form_id, data, saved_at, who, note) VALUES (?,?,?,?,?)', (form_id, data, now(), who, note))
    con.execute('DELETE FROM revisions WHERE form_id=? AND id NOT IN (SELECT id FROM revisions WHERE form_id=? ORDER BY id DESC LIMIT 300)', (form_id, form_id))


def backup_loop():
    """A copy of the database every day in $SURVEY_DATA/backups (the last 30 kept)."""
    folder = os.path.join(DATA, 'backups')
    os.makedirs(folder, exist_ok=True)
    while True:
        name = os.path.join(folder, 'survey-%s.sqlite' % datetime.now(BAGHDAD).strftime('%Y%m%d'))
        if not os.path.exists(name):
            try:
                src = connect()
                dst = sqlite3.connect(name)
                src.backup(dst)
                dst.close()
                src.close()
                for old in sorted(f for f in os.listdir(folder) if f.startswith('survey-'))[:-30]:
                    os.remove(os.path.join(folder, old))
            except Exception as e:  # noqa: BLE001
                print('backup failed:', e, flush=True)
        time.sleep(3600)


# ---------------------------------------------------------------- Excel
def fill_sheet(ws, values):
    for rk, row in values.items():
        r = int(rk[1:])
        for col, v in row.items():
            kind = CELLS.get((r, col))
            cell = ws['%s%d' % (col, r)]
            if kind in ('number', 'short') and re.fullmatch(r'-?\d+(\.\d+)?', v):
                cell.value = float(v) if '.' in v else int(v)
            elif kind == 'date' and v:
                cell.value = datetime.strptime(v, '%Y-%m-%d')
                cell.number_format = 'yyyy-mm-dd'
            else:
                cell.value = v


def sheet_name(header, used):
    base = re.sub(r'[\[\]\*\?/\\:]', ' ', header.get('D') or header.get('C') or 'استمارة').strip()[:28] or 'استمارة'
    name, n = base, 2
    while name in used:
        name = '%s %d' % (base[:26], n)
        n += 1
    used.add(name)
    return name


def workbook_for(forms):
    """The original form, filled: one sheet per centre, and a first sheet listing them all."""
    wb = openpyxl.load_workbook(TEMPLATE)
    blank = wb.worksheets[0]
    used = set()
    if len(forms) == 1:
        fill_sheet(blank, forms[0]['values'])
        blank.title = sheet_name(forms[0]['header'], used)
        return wb
    total = wb.create_sheet('الملخص', 0)
    total.sheet_view.rightToLeft = True
    head = [f['label'] for f in SCHEMA['header']] + ['آخر تعديل']
    for s in SCHEMA['sections']:
        for it in s['items']:
            for c in s['cols']:
                head.append('%s — %s' % (it['label'], c['label']))
    total.append(head)
    bold = openpyxl.styles.Font(bold=True, color='FFFFFF')
    navy = openpyxl.styles.PatternFill('solid', fgColor='17375E')
    for cell in total[1]:
        cell.font, cell.fill = bold, navy
        cell.alignment = openpyxl.styles.Alignment(wrap_text=True, vertical='center')
    total.freeze_panes = 'B2'
    for f in forms:
        v = f['values']
        row = [v.get('r%d' % SCHEMA['header_row'], {}).get(h['col'], '') for h in SCHEMA['header']] + [f['updated_at'].replace('T', ' ')]
        for s in SCHEMA['sections']:
            for it in s['items']:
                for c in s['cols']:
                    x = v.get('r%d' % it['row'], {}).get(c['col'], '')
                    row.append(int(x) if c['kind'] in ('number', 'short') and re.fullmatch(r'\d+', x) else x)
        total.append(row)
    for i in range(1, len(head) + 1):
        total.column_dimensions[openpyxl.utils.get_column_letter(i)].width = 22
    for f in forms:
        ws = wb.copy_worksheet(blank)
        ws.sheet_view.rightToLeft = True
        for rng in blank.merged_cells.ranges:
            if str(rng) not in [str(x) for x in ws.merged_cells.ranges]:
                ws.merge_cells(str(rng))
        fill_sheet(ws, f['values'])
        ws.title = sheet_name(f['header'], used)
    wb.remove(blank)
    return wb


def xlsx_bytes(wb):
    out = io.BytesIO()
    wb.save(out)
    return out.getvalue()


# ---------------------------------------------------------------- HTTP
HITS = {}


def limited(ip, cost=1, per_minute=1200):
    t = time.time()
    with LOCK:
        a, last = HITS.get(ip, (per_minute, t))
        a = min(per_minute, a + (t - last) * per_minute / 60) - cost
        HITS[ip] = (a, t)
        if len(HITS) > 10000:
            HITS.clear()
    return a < 0


class Handler(BaseHTTPRequestHandler):
    server_version = 'survey'

    def log_message(self, fmt, *args):
        pass

    def ip(self):
        return (self.headers.get('X-Forwarded-For') or self.client_address[0]).split(',')[0].strip()

    def who(self):
        return hashlib.sha256(('%s|%s' % (self.ip(), self.headers.get('User-Agent', ''))).encode()).hexdigest()[:12]

    def send(self, code, body=b'', ctype='application/json; charset=utf-8', extra=None):
        if isinstance(body, (dict, list)):
            body = json.dumps(body, ensure_ascii=False).encode()
        elif isinstance(body, str):
            body = body.encode()
        self.send_response(code)
        self.send_header('Content-Type', ctype)
        self.send_header('Content-Length', str(len(body)))
        self.send_header('X-Content-Type-Options', 'nosniff')
        self.send_header('Referrer-Policy', 'same-origin')
        self.send_header('Cache-Control', 'no-store' if ctype.startswith('application/') else 'no-cache')
        if ctype.startswith('text/html'):
            self.send_header('Content-Security-Policy', "default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'")
        for k, v in (extra or {}).items():
            self.send_header(k, v)
        self.end_headers()
        if self.command != 'HEAD':
            self.wfile.write(body)

    def body(self):
        n = int(self.headers.get('Content-Length') or 0)
        if n > MAX_BODY:
            raise ValueError('too large')
        raw = self.rfile.read(n) if n else b'{}'
        return json.loads(raw.decode() or '{}')

    def route(self, method):
        url = urlparse(self.path)
        p = url.path.rstrip('/') or '/'
        if limited(self.ip(), 5 if p.endswith('.xlsx') else 1):
            return self.send(429, {'error': 'طلبات كثيرة؛ انتظر قليلاً.'})
        if method == 'GET' and p == '/health':
            return self.send(200, {'ok': True})
        if method == 'GET' and p in ('/', '/index.html'):
            return self.static('index.html')
        if method == 'GET' and p.startswith('/static/'):
            return self.static(p[len('/static/'):])
        if method == 'GET' and p == '/api/schema':
            return self.send(200, SCHEMA)
        if p == '/api/forms':
            if method == 'GET':
                return self.list_forms(parse_qs(url.query).get('bin', [''])[0] == '1')
            if method == 'POST':
                return self.create()
        if method == 'GET' and p == '/api/export.xlsx':
            with db() as con:
                forms = [form_out(r) for r in con.execute('SELECT * FROM forms WHERE deleted_at IS NULL ORDER BY id')]
            if not forms:
                return self.send(404, {'error': 'لا توجد استمارات.'})
            return self.xlsx(workbook_for(forms), 'استبيان-المراكز-الصحية-%s.xlsx' % datetime.now(BAGHDAD).strftime('%Y-%m-%d'))
        m = re.fullmatch(r'/api/forms/(\d+)(\.xlsx|/history|/history/(\d+)/restore|/delete|/restore)?', p)
        if m:
            fid, tail, rid = int(m.group(1)), m.group(2) or '', m.group(3)
            if method == 'GET' and tail == '':
                return self.get_form(fid)
            if method == 'PUT' and tail == '':
                return self.save(fid)
            if method == 'GET' and tail == '.xlsx':
                return self.form_xlsx(fid)
            if method == 'GET' and tail == '/history':
                return self.history(fid)
            if method == 'POST' and rid:
                return self.restore_revision(fid, int(rid))
            if method == 'POST' and tail in ('/delete', '/restore'):
                return self.bin(fid, tail == '/delete')
        return self.send(404, {'error': 'غير موجود'})

    def static(self, name):
        if not re.fullmatch(r'[A-Za-z0-9_.-]+', name):
            return self.send(404, 'not found', 'text/plain')
        path = os.path.join(HERE, 'static', name)
        if not os.path.isfile(path):
            return self.send(404, 'not found', 'text/plain')
        ctype = {'html': 'text/html; charset=utf-8', 'js': 'text/javascript; charset=utf-8', 'css': 'text/css; charset=utf-8',
                 'svg': 'image/svg+xml', 'png': 'image/png', 'webmanifest': 'application/manifest+json'}.get(name.rsplit('.', 1)[-1], 'application/octet-stream')
        with open(path, 'rb') as f:
            return self.send(200, f.read(), ctype)

    def xlsx(self, wb, filename):
        return self.send(200, xlsx_bytes(wb), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                         {'Content-Disposition': "attachment; filename=\"survey.xlsx\"; filename*=UTF-8''%s" % quote(filename)})

    def list_forms(self, deleted):
        with db() as con:
            rows = con.execute('SELECT * FROM forms WHERE deleted_at IS %s NULL ORDER BY updated_at DESC' % ('NOT' if deleted else '')).fetchall()
        out = []
        for r in rows:
            f = form_out(r)
            del f['values']
            out.append(f)
        return self.send(200, {'forms': out, 'items': sum(len(s['items']) for s in SCHEMA['sections'])})

    def create(self):
        try:
            values = clean(self.body().get('values'))
        except Exception:  # noqa: BLE001
            return self.send(400, {'error': 'طلب غير صالح'})
        with LOCK, db() as con:
            cur = con.execute('INSERT INTO forms (data, created_at, updated_at) VALUES (?,?,?)', (json.dumps(values, ensure_ascii=False), now(), now()))
            remember(con, cur.lastrowid, values, self.who(), 'أُنشئت')
            row = con.execute('SELECT * FROM forms WHERE id=?', (cur.lastrowid,)).fetchone()
        return self.send(201, form_out(row))

    def get_form(self, fid):
        with db() as con:
            row = con.execute('SELECT * FROM forms WHERE id=?', (fid,)).fetchone()
        return self.send(200, form_out(row)) if row else self.send(404, {'error': 'الاستمارة غير موجودة'})

    def save(self, fid):
        """Saves the whole form if nobody saved since `version`; otherwise 409 with the newer form."""
        try:
            b = self.body()
            values, version = clean(b.get('values')), int(b.get('version'))
        except Exception:  # noqa: BLE001
            return self.send(400, {'error': 'طلب غير صالح'})
        with LOCK, db() as con:
            row = con.execute('SELECT * FROM forms WHERE id=?', (fid,)).fetchone()
            if not row:
                return self.send(404, {'error': 'الاستمارة غير موجودة'})
            if row['deleted_at']:
                return self.send(410, {'error': 'هذه الاستمارة محذوفة؛ استرجعها أولاً.'})
            if row['version'] != version:
                return self.send(409, form_out(row))
            con.execute('UPDATE forms SET data=?, version=version+1, updated_at=? WHERE id=?', (json.dumps(values, ensure_ascii=False), now(), fid))
            remember(con, fid, values, self.who())
            row = con.execute('SELECT * FROM forms WHERE id=?', (fid,)).fetchone()
        return self.send(200, {'version': row['version'], 'updated_at': row['updated_at'], 'filled': filled(values)})

    def form_xlsx(self, fid):
        with db() as con:
            row = con.execute('SELECT * FROM forms WHERE id=?', (fid,)).fetchone()
        if not row:
            return self.send(404, {'error': 'الاستمارة غير موجودة'})
        f = form_out(row)
        name = ' - '.join(x for x in (f['header'].get('D'), f['header'].get('B')) if x) or 'استمارة %d' % fid
        return self.xlsx(workbook_for([f]), '%s.xlsx' % re.sub(r'[\\/:*?"<>|]', ' ', name))

    def history(self, fid):
        with db() as con:
            rows = con.execute('SELECT id, saved_at, who, note, data FROM revisions WHERE form_id=? ORDER BY id DESC', (fid,)).fetchall()
        out = []
        for r in rows:
            v = json.loads(r['data'])
            out.append({'id': r['id'], 'saved_at': r['saved_at'], 'who': r['who'][:6], 'note': r['note'], 'filled': filled(v), 'header': summary(v)})
        return self.send(200, {'revisions': out})

    def restore_revision(self, fid, rid):
        with LOCK, db() as con:
            rev = con.execute('SELECT * FROM revisions WHERE id=? AND form_id=?', (rid, fid)).fetchone()
            if not rev:
                return self.send(404, {'error': 'غير موجود'})
            con.execute('UPDATE forms SET data=?, version=version+1, updated_at=? WHERE id=?', (rev['data'], now(), fid))
            remember(con, fid, json.loads(rev['data']), self.who(), 'استُرجعت نسخة %s' % rev['saved_at'].replace('T', ' '))
            row = con.execute('SELECT * FROM forms WHERE id=?', (fid,)).fetchone()
        return self.send(200, form_out(row))

    def bin(self, fid, delete):
        with LOCK, db() as con:
            row = con.execute('SELECT * FROM forms WHERE id=?', (fid,)).fetchone()
            if not row:
                return self.send(404, {'error': 'الاستمارة غير موجودة'})
            con.execute('UPDATE forms SET deleted_at=?, version=version+1 WHERE id=?', (now() if delete else None, fid))
            remember(con, fid, json.loads(row['data']), self.who(), 'حُذفت' if delete else 'استُرجعت من المحذوفات')
        return self.send(200, {'ok': True})

    def handle_method(self, method):
        try:
            self.route(method)
        except (BrokenPipeError, ConnectionResetError):
            pass
        except Exception as e:  # noqa: BLE001
            print('error:', repr(e), flush=True)
            try:
                self.send(500, {'error': 'خطأ في الخادم'})
            except Exception:  # noqa: BLE001
                pass

    def do_GET(self):
        self.handle_method('GET')

    def do_HEAD(self):
        self.handle_method('GET')

    def do_POST(self):
        self.handle_method('POST')

    def do_PUT(self):
        self.handle_method('PUT')


def main():
    threading.Thread(target=backup_loop, daemon=True).start()
    srv = ThreadingHTTPServer(('0.0.0.0', PORT), Handler)
    print('survey on :%d, %d sections, %d items' % (PORT, len(SCHEMA['sections']), sum(len(s['items']) for s in SCHEMA['sections'])), flush=True)
    srv.serve_forever()


if __name__ == '__main__':
    main()
