"""
End-to-end check of the AMC connector with the real AMC: build the sheets, fill chosen boxes on
the rendered pages (where AMC's own layout says they are), scan them back in, and compare the
exported ticked boxes with what was filled.
"""
import json, os, sqlite3, subprocess, sys, time, urllib.request
from PIL import Image, ImageDraw

URL = os.environ.get('AMC_URL', 'http://127.0.0.1:8080')
TOKEN = os.environ['AMC_TOKEN']
P = 'e2etest'
here = os.path.dirname(__file__)


def call(method, path, body=None, ctype='application/json', raw=False):
    req = urllib.request.Request(URL + path, method=method, data=body, headers={'Authorization': 'Bearer ' + TOKEN, 'Content-Type': ctype})
    try:
        with urllib.request.urlopen(req, timeout=900) as r:
            data = r.read()
            return r.status, (data if raw else json.loads(data or b'{}'))
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode(errors='replace')


def sh(*cmd):
    print('+', ' '.join(cmd), flush=True)
    return subprocess.run(cmd, check=True, capture_output=True, text=True).stdout


print(call('GET', '/health'))
code, s = call('PUT', f'/projects/{P}', json.dumps({'source': open(f'{here}/sample.txt', encoding='utf-8').read(),
                                                  'students': open(f'{here}/students.csv', encoding='utf-8').read()}).encode())
print('build', code, json.dumps(s, ensure_ascii=False)[:3000])
assert code == 200 and s['state'] == 'built', 'build failed'
code, pdf = call('GET', f'/projects/{P}/subject.pdf', raw=True)
assert code == 200 and pdf[:4] == b'%PDF'
open('/tmp/subject.pdf', 'wb').write(pdf)

# AMC's layout: where every answer box is on every page of every student's copy.
sh('docker', 'cp', f'amc:/data/{P}/data/layout.sqlite', '/tmp/layout.sqlite')
db = sqlite3.connect('/tmp/layout.sqlite')
for t in db.execute("select name, sql from sqlite_master where type='table'"):
    print(t[0], '::', t[1])
pages = db.execute('select student, page, dpi, width, height from layout_page order by student, page').fetchall()
print('pages', pages)
names = dict(db.execute('select question, name from layout_question'))
print('questions', names)
boxes = db.execute('select student, page, question, answer, xmin, xmax, ymin, ymax from layout_box where role=1').fetchall()
print(len(boxes), 'answer boxes')

# What each student "answers" (1-based answers as in the source).
plan = {1: {'q11': [1], 'q12': [1, 2], 'q13': [1], 'q14': [1]}, 2: {'q11': [2], 'q12': [1], 'q13': [2], 'q14': [2]}, 3: {'q11': [3], 'q12': [], 'q13': [1], 'q14': []}}

# Render the subject at the layout's resolution, one page picture per PDF page, in order.
dpi = pages[0][2]
sh('docker', 'exec', 'amc', 'sh', '-c', f'mkdir -p /tmp/render && pdftoppm -r {int(dpi)} -png /data/{P}/subject.pdf /tmp/render/page')
sh('docker', 'cp', 'amc:/tmp/render', '/tmp/render')
files = sorted(f for f in os.listdir('/tmp/render') if f.endswith('.png'))
print(files)
assert len(files) == len(pages), (len(files), len(pages))
for (student, page, _d, w, h), f in zip(pages, files):
    im = Image.open('/tmp/render/' + f).convert('L')
    sx, sy = im.width / w, im.height / h
    dr = ImageDraw.Draw(im)
    for (st, pg, q, a, x0, x1, y0, y1) in boxes:
        if st == student and pg == page and a in plan.get(student, {}).get(names.get(q), []):
            pad = 0.15 * (x1 - x0)
            dr.rectangle([(x0 + pad) * sx, (y0 + pad) * sy, (x1 - pad) * sx, (y1 - pad) * sy], fill=20)
    out = f'/tmp/scan-{student}-{page}.png'
    im.convert('RGB').save(out)
    code, r = call('POST', f'/projects/{P}/scans?name=scan-{student}-{page}.png', open(out, 'rb').read(), 'image/png')
    assert code == 200, r

code, r = call('POST', f'/projects/{P}/process', b'')
assert code == 202, r
for _ in range(240):
    time.sleep(3)
    code, s = call('GET', f'/projects/{P}/status')
    if s['state'] in ('ready', 'failed'):
        break
print('status', json.dumps(s, ensure_ascii=False)[:4000])
assert s['state'] == 'ready', 'processing failed'
# How dark AMC measured every answer box (to see the margin between filled and empty).
sh('docker', 'cp', f'amc:/data/{P}/data/capture.sqlite', '/tmp/capture.sqlite')
cap = sqlite3.connect('/tmp/capture.sqlite')
print([r[0] for r in cap.execute("select sql from sqlite_master where name='capture_zone'")])
for row in cap.execute('select student, id_a, id_b, black, total from capture_zone where type=4 order by student, id_a, id_b'):
    st, q, a, black, total = row
    filled = a in plan.get(st, {}).get(names.get(q), [])
    print(f'student {st} {names.get(q)} box {a}: {black}/{total} = {black / max(1, total):.3f}', 'FILLED' if filled else '')
code, csv = call('GET', f'/projects/{P}/results.csv', raw=True)
csv = csv.decode('utf-8')
print(csv)

import csv as csvmod, io
rows = list(csvmod.DictReader(io.StringIO(csv)))
header = rows[0].keys() if rows else []
print('header', list(header))
key = next(k for k in header if any(r[k] in ('S1', 'S2', 'S3') for r in rows))
ticked = {k.split(':', 1)[1]: k for k in header if k.upper().startswith(('TICKED:', 'CHECKED:'))}
bad = 0
for r in rows:
    st = int(r[key][1:])
    for q, want in plan[st].items():
        got = r[ticked[q]] if q in ticked else None
        expect = ''.join(chr(64 + a) for a in want)
        ok = got == expect
        bad += not ok
        print(f'student {st} {q}: filled {expect or "-"} read {got!r}', 'OK' if ok else 'WRONG')
assert len(rows) == 3, 'three students expected'
assert bad == 0, f'{bad} answers read wrong'
print('E2E OK')
