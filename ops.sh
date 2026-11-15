for u in "http://167.86.66.47:8080/api/v1/" ; do
  curl -s -m 25 -o /tmp/s.json -w "%{http_code} %{time_total}s\n" "$u"
  python3 - <<'PY'
import json
try:
    d=json.load(open('/tmp/s.json'))
except Exception as e:
    print('not json', open('/tmp/s.json','rb').read(200)); raise SystemExit
p=d.get('paths',{})
for r in ['/oauth/token','/libraries','/patrons','/items','/biblios','/checkouts','/checkouts/availability','/checkouts/{checkout_id}/allows_renewal','/checkouts/{checkout_id}/renewal','/checkins','/holds','/deleted/items','/deleted/biblios']:
    print(r, 'yes' if r in p else 'NO', ','.join(k for k in p.get(r,{}) if k in('get','post','put','delete')))
PY
done
rm -f /tmp/s.json
curl -s -m 15 -o /dev/null -w "opac80 %{http_code}\n" http://167.86.66.47/
