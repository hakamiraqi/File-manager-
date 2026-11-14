cd /tmp; grep -oE ".{50}address.{80}" a.js | head -5
for H in iquc.koha.lib.iq; do
for s in https http; do
for p in "/" "/cgi-bin/koha/oai.pl?verb=Identify" "/cgi-bin/koha/opac-search.pl?q=anatomy&format=rss" "/cgi-bin/koha/opac-search.pl?idx=nb&q=9780323414920&format=rss" "/api/v1/public/biblios/1" "/cgi-bin/koha/opac-export.pl?op=export&bib=1&format=marcxml"; do
  printf "%s://%s%s -> " $s $H "$p"; curl -sS -m 15 -o /tmp/r -w "%{http_code} %{content_type}\n" -H 'Accept: application/marcxml+xml, */*' "$s://$H$p"; head -c 220 /tmp/r | tr '\n' ' '; echo
done; done
for p in 80 443 210 2100 9998 9999 8080 8000; do timeout 6 bash -c "</dev/tcp/$H/$p" 2>/dev/null && echo "$H:$p open" || echo "$H:$p closed"; done
done
