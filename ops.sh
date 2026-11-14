curl -sS -m 20 https://lib.iq/ -o /tmp/l.html
grep -oE '(href|action|src)="[^"]+"' /tmp/l.html | grep -vE '\.(css|png|jpg|svg|woff2?|ico|webp)' | sort -u | head -30
grep -oiE 'koha|generator[^>]{0,80}' /tmp/l.html | sort | uniq -c | head
for H in lib.iq opac.lib.iq; do
for p in "/cgi-bin/koha/oai.pl?verb=Identify" "/cgi-bin/koha/opac-search.pl?q=anatomy&format=rss" "/api/v1/public/biblios/1" "/cgi-bin/koha/opac-export.pl?op=export&bib=1&format=marcxml" "/biblios?version=1.1&operation=explain"; do
  printf "%s%s -> " $H "$p"; curl -sS -m 15 -o /tmp/r -w "%{http_code} %{content_type}\n" "https://$H$p"; head -c 160 /tmp/r | tr '\n' ' '; echo
done
for p in 210 9998 9999; do timeout 6 bash -c "</dev/tcp/$H/$p" 2>/dev/null && echo "$H:$p open" || echo "$H:$p closed"; done
done
