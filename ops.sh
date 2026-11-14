curl -sS -m 20 -L https://iquc.org/ -o /tmp/h.html; wc -c /tmp/h.html
grep -oE '<title>[^<]*' /tmp/h.html
grep -oE '(href|src|action)="[^"]+"' /tmp/h.html | grep -vE '\.(css|png|jpg|svg|woff2?|ico)' | sort -u | head -40
grep -oE 'https?://[a-zA-Z0-9.-]+' /tmp/h.html | sort | uniq -c | sort -rn | head -15
