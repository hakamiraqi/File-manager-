cd /tmp; curl -sS -m 20 https://lib.iq/sites.js -o s.js; curl -sS -m 30 "https://lib.iq/assets/js/app.js?117" -o a.js; wc -c s.js a.js
head -c 600 s.js; echo
grep -oE "(https?:)?//[a-zA-Z0-9./:_-]+|['\"]/(api|search|svc|cgi-bin|sru|z3950|marc|export|record|biblio)[a-zA-Z0-9/_.?=&-]*" a.js | sort | uniq -c | sort -rn | head -30
grep -oE ".{60}(marc|MARC|export|sru|Z39).{60}" a.js | head -10
