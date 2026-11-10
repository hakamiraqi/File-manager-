# Lists (and, with --download DIR, downloads) a public Google Drive folder tree.
# Uses the public embedded folder view, so the folder must be shared as "anyone with the link".
import json, os, re, sys, time, html, urllib.request, urllib.error, http.cookiejar
ROOT = sys.argv[1]
OUT = sys.argv[sys.argv.index('--download') + 1] if '--download' in sys.argv else None
jar = http.cookiejar.CookieJar()
op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
op.addheaders = [('User-Agent', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/124 Safari/537.36'), ('Accept-Language', 'ar,en')]
def get(url, tries=4):
    for t in range(tries):
        try:
            return op.open(url, timeout=120)
        except Exception as e:
            if t == tries - 1: raise
            time.sleep(5 * (t + 1))
ENTRY = re.compile(r'<div class="flip-entry" id="entry-([\w-]+)".*?<a href="([^"]+)".*?<div class="flip-entry-title">(.*?)</div>', re.S)
def listing(fid):
    page = get(f'https://drive.google.com/embeddedfolderview?id={fid}#list').read().decode('utf-8', 'replace')
    title = re.search(r'<title>(.*?)</title>', page, re.S)
    out = []
    for eid, href, name in ENTRY.findall(page):
        name = html.unescape(name).strip()
        kind = 'folder' if '/folders/' in href else ('gdoc' if 'docs.google.com' in href else 'file')
        out.append({'id': eid, 'name': name, 'kind': kind, 'href': html.unescape(href)})
    return (html.unescape(title.group(1)).strip() if title else fid), out
def walk(fid, path, items):
    _, entries = listing(fid)
    for e in entries:
        p = path + [e['name']]
        if e['kind'] == 'folder':
            items.append({'kind': 'folder', 'path': p})
            walk(e['id'], p, items)
        else:
            items.append({'kind': e['kind'], 'path': p, 'id': e['id'], 'href': e['href']})
    time.sleep(0.3)
title, _ = listing(ROOT)
items = []
walk(ROOT, [], items)
files = [i for i in items if i['kind'] != 'folder']
print(f'ROOT: {title}')
print(f'FOLDERS: {sum(1 for i in items if i["kind"] == "folder")}  FILES: {len(files)}  GOOGLE-DOCS: {sum(1 for i in files if i["kind"] == "gdoc")}')
ext = {}
for f in files:
    e = os.path.splitext(f['path'][-1])[1].lower() or '(none)'
    ext[e] = ext.get(e, 0) + 1
print('BY TYPE:', ', '.join(f'{k} {v}' for k, v in sorted(ext.items(), key=lambda x: -x[1])[:15]))
tops = {}
for i in items:
    k = i['path'][0] if len(i['path']) > 1 else '(top level files)'
    if i['kind'] != 'folder': tops[k] = tops.get(k, 0) + 1
for k, v in sorted(tops.items(), key=lambda x: -x[1]): print(f'  {v:5}  {k}')
json.dump({'title': title, 'items': items}, open('tree.json', 'w'), ensure_ascii=False)
if not OUT: sys.exit(0)

def gdoc_export(href, fid):
    if '/document/' in href: return f'https://docs.google.com/document/d/{fid}/export?format=pdf', '.pdf'
    if '/spreadsheets/' in href: return f'https://docs.google.com/spreadsheets/d/{fid}/export?format=xlsx', '.xlsx'
    if '/presentation/' in href: return f'https://docs.google.com/presentation/d/{fid}/export/pdf', '.pdf'
    return None, None
done, failed, total = 0, [], 0
for f in files:
    rel = os.path.join(OUT, *f['path'])
    if f['kind'] == 'gdoc':
        url, ext = gdoc_export(f['href'], f['id'])
        if not url: failed.append(f['path']); continue
        if not rel.lower().endswith(ext): rel += ext
    else:
        url = f'https://drive.usercontent.google.com/download?id={f["id"]}&export=download&confirm=t'
    os.makedirs(os.path.dirname(rel), exist_ok=True)
    if os.path.exists(rel) and os.path.getsize(rel) > 0: done += 1; total += os.path.getsize(rel); continue
    try:
        r = get(url)
        ctype = r.headers.get('Content-Type', '')
        tmp = rel + '.part'
        with open(tmp, 'wb') as w:
            while True:
                b = r.read(1 << 20)
                if not b: break
                w.write(b)
        if 'text/html' in ctype and os.path.getsize(tmp) < 200000 and not rel.lower().endswith(('.html', '.htm')):
            os.remove(tmp); failed.append(f['path']); continue
        os.replace(tmp, rel); done += 1; total += os.path.getsize(rel)
    except Exception as e:
        failed.append(f['path'])
    if done % 50 == 0: print(f'  downloaded {done}/{len(files)}  {total / 1e9:.2f} GB', flush=True)
print(f'DOWNLOADED: {done}/{len(files)}  SIZE: {total / 1e9:.2f} GB  FAILED: {len(failed)}')
json.dump(failed, open('failed.json', 'w'), ensure_ascii=False)
