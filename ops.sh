for r in teogramm/koha koha/koha-testing; do echo "== $r"; curl -s -m 20 "https://hub.docker.com/v2/repositories/$r/tags?page_size=8" | python3 -c "
import sys,json
d=json.load(sys.stdin)
for t in d.get('results',[]): print(t['name'], t.get('last_updated','')[:10], sorted({i.get('architecture') for i in t.get('images',[])}))" 2>&1 | head -10; done
