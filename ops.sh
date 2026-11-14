curl -sS -m 15 https://iquc.koha.lib.iq/ -D /tmp/hd -o /tmp/r; grep -iE "^(server|cf-ray|x-)" /tmp/hd; grep -oE '<title>[^<]*|<h1[^>]*>[^<]*|<h2[^>]*>[^<]*' /tmp/r | head -5
curl -sS -m 10 -o /dev/null -w "lib.iq ip: %{remote_ip}\n" https://lib.iq/; curl -sS -m 10 -o /dev/null -w "koha ip: %{remote_ip}\n" https://iquc.koha.lib.iq/
