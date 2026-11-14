H=iquc.org
echo "== home"; curl -sS -m 20 -L -o /tmp/h.html -w "%{http_code} %{url_effective}\n" https://$H/; grep -oiE 'koha[^"<]{0,40}|generator[^>]{0,80}' /tmp/h.html | sort -u | head -8
grep -oE 'href="[^"]*(opac|cgi-bin)[^"]*"' /tmp/h.html | sort -u | head -10
for u in "https://$H/cgi-bin/koha/oai.pl?verb=Identify" "https://$H/cgi-bin/koha/opac-search.pl?q=anatomy&format=rss" "https://$H/api/v1/public/biblios/1" "https://$H/cgi-bin/koha/opac-export.pl?op=export&bib=1&format=marcxml" "http://$H:9998/biblios?version=1.1&operation=explain" "http://$H:210/biblios?version=1.1&operation=explain" "https://$H/biblios?version=1.1&operation=explain"; do
  echo "== $u"; curl -sS -m 15 -o /tmp/r -w "%{http_code} %{content_type}\n" -H 'Accept: application/marcxml+xml, */*' "$u" 2>&1 | tail -1; head -c 300 /tmp/r | tr '\n' ' '; echo
done
echo "== tcp"; for p in 210 9998 9999 7090; do timeout 6 bash -c "</dev/tcp/$H/$p" 2>/dev/null && echo "$p open" || echo "$p closed"; done
