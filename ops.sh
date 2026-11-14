cat /tmp/h.html 2>/dev/null; curl -sS -m 20 https://iquc.org/ ; echo
for H in lib.iq opac.lib.iq; do
echo "### $H"; curl -sS -m 20 -L -o /tmp/l.html -w "%{http_code} %{url_effective}\n" https://$H/; grep -oE '<title>[^<]*' /tmp/l.html; grep -oiE 'Koha [0-9.]+|koha-[a-z]+|cgi-bin/koha/[a-z-]+\.pl' /tmp/l.html | sort -u | head -8
grep -oE 'https?://[a-zA-Z0-9.:-]+' /tmp/l.html | sort | uniq -c | sort -rn | head -8
done
