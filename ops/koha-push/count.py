import json, os, re, requests
BASE = 'http://167.86.66.47:8080'
s = requests.Session()
csrf = lambda h: re.search(r'<meta name="csrf-token" content="([^"]+)"', h).group(1)
p = s.get(f'{BASE}/cgi-bin/koha/mainpage.pl', timeout=90).text
s.post(f'{BASE}/cgi-bin/koha/mainpage.pl', timeout=90, data={'csrf_token': csrf(p), 'op': 'cud-login', 'koha_login_context': 'intranet',
       'login_userid': os.environ['KOHA_USER'], 'login_password': os.environ['KOHA_PASS'], 'branch': 'DENT'})
s.headers['Accept'] = 'application/json'
r = s.get(f'{BASE}/api/v1/biblios', params={'q': json.dumps({'biblio_id': {'>': 2413}}), '_per_page': 1}, timeout=120)
print('new biblios since 2414:', r.status_code, r.headers.get('X-Total-Count'))
r = s.get(f'{BASE}/api/v1/items', params={'q': json.dumps({'biblio_id': {'>': 2413}}), '_per_page': 1}, timeout=120)
print('their items:', r.status_code, r.headers.get('X-Total-Count'))
