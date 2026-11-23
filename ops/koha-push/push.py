"""Adds the dental college books to Koha with a staff account that may catalogue but not import.

Signs in like the staff interface does (session cookie + CSRF token) and uses Koha's REST API:
POST /biblios (MARCXML) then POST /biblios/{id}/items for each copy. Settings in run.json:
mode "probe" (sign in and read only), or "push" with start/limit (book indexes in the .mrc).
"""
import json, os, re, sys, time, threading
from concurrent.futures import ThreadPoolExecutor
import requests
from pymarc import MARCReader, record_to_xml

BASE = 'http://167.86.66.47:8080'
cfg = json.load(open(os.path.join(os.path.dirname(__file__), 'run.json')))
s = requests.Session()
s.headers['User-Agent'] = 'library-import'

def csrf(html):
    m = re.search(r'<meta name="csrf-token" content="([^"]+)"', html)
    return m.group(1) if m else None

def login():
    page = s.get(f'{BASE}/cgi-bin/koha/mainpage.pl', timeout=90).text
    r = s.post(f'{BASE}/cgi-bin/koha/mainpage.pl', timeout=90, data={
        'csrf_token': csrf(page), 'op': 'cud-login', 'koha_login_context': 'intranet',
        'login_userid': os.environ['KOHA_USER'], 'login_password': os.environ['KOHA_PASS'], 'branch': 'DENT'})
    if 'loginform' in r.text:
        sys.exit('Sign-in refused: check the KOHA_USER / KOHA_PASS secrets.')
    tok = csrf(r.text)
    s.headers['CSRF-TOKEN'] = tok
    s.headers['Accept'] = 'application/json'
    print('Signed in as', os.environ['KOHA_USER'])

def api(method, path, **kw):
    for attempt in range(4):
        try:
            r = s.request(method, f'{BASE}/api/v1{path}', timeout=120, **kw)
            if r.status_code in (502, 503, 504):
                raise requests.RequestException(f'HTTP {r.status_code}')
            return r
        except requests.RequestException as e:
            time.sleep(5 * (attempt + 1))
    raise RuntimeError(f'{method} {path} kept failing')

login()
t = time.time(); r = api('GET', '/libraries', params={'_per_page': 3})
print(f'GET /libraries -> {r.status_code} in {time.time()-t:.1f}s: {r.text[:200]}')
r = api('GET', '/item_types', params={'_per_page': 50})
print('item types:', r.status_code, [i.get('item_type_id') for i in r.json()] if r.ok else r.text[:200])
if cfg['mode'] == 'probe' or not r.ok:
    sys.exit(0)

books = list(MARCReader(open(os.path.join(os.path.dirname(__file__), 'books-for-koha-DENT.mrc'), 'rb'), to_unicode=True, force_utf8=True))
start, limit = cfg.get('start', 0), cfg.get('limit', len(books))
todo = list(enumerate(books))[start:start + limit]
lock = threading.Lock()
stats = {'books': 0, 'items': 0, 'failed': 0}

def push(arg):
    i, rec = arg
    items = rec.get_fields('952')
    rec.remove_fields('952')
    xml = record_to_xml(rec, namespace=True)
    r = api('POST', '/biblios', data=xml, headers={'Content-Type': 'application/marcxml+xml', 'x-framework-id': ''})
    if r.status_code not in (200, 201):
        with lock:
            stats['failed'] += 1
            print(f'FAIL book {i}: {r.status_code} {r.text[:300]}', flush=True)
        return
    bid = r.json().get('id') or r.json().get('biblio_id')
    ok = 0
    for f in items:
        body = {'home_library_id': 'DENT', 'holding_library_id': 'DENT', 'item_type_id': f['y'] or 'BK'}
        if f['o']:
            body['callnumber'] = f['o']
        ri = api('POST', f'/biblios/{bid}/items', json=body)
        if ri.status_code in (200, 201):
            ok += 1
        else:
            with lock:
                print(f'FAIL item of book {i} (biblio {bid}): {ri.status_code} {ri.text[:300]}', flush=True)
    with lock:
        stats['books'] += 1; stats['items'] += ok
        print(f'DONE {i} biblio={bid} items={ok}/{len(items)}', flush=True)

with ThreadPoolExecutor(cfg.get('workers', 3)) as ex:
    list(ex.map(push, todo))
print('Summary:', stats)
