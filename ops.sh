set -e
cd /home/ubuntu/koha-test
docker volume create koha-test_koha_setup >/dev/null
V=/var/lib/docker/volumes/koha-test_koha_setup/_data
[ -f $V/admin.pass ] || openssl rand -base64 18 | tr -d '/+=' | head -c 16 > $V/admin.pass
[ -f $V/api.pass ] || openssl rand -base64 24 | tr -d '/+=' | head -c 20 > $V/api.pass
chmod 600 $V/*.pass
docker compose up -d 2>&1 | tail -2
grep -q "koha.azoclinic.duckdns.org" /home/ubuntu/headscale/Caddyfile || cat >> /home/ubuntu/headscale/Caddyfile <<'CADDY'

# Test Koha (staff interface and OPAC) for trying the FileNest link
koha.azoclinic.duckdns.org {
	encode gzip
	reverse_proxy koha-test:8080
}

opac.azoclinic.duckdns.org {
	encode gzip
	reverse_proxy koha-test:80
}
CADDY
docker exec caddy caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile 2>&1 | tail -2
echo 'LS0tLS1CRUdJTiBQVUJMSUMgS0VZLS0tLS0KTUlJQm9qQU5CZ2txaGtpRzl3MEJBUUVGQUFPQ0FZOEFNSUlCaWdLQ0FZRUExWFlrdHVoc1VXTlhuYWFQZnJyaApObldrOHI2eTRiMDBlK3kyMGMwYy95YThjZjV1dWRCNHpOZDlkbW5lNEJHdFFCYUl3dkFNMU1hRlBOMmMxcERvCjNsQzNzdnBnNExRVVg4aDh0ZGtqVTRLQWprMTJuVUNTYm16N1NPbDZsc2FySlpweUNCK0pHUGViTDB5WVlCcEUKZEJIejZ5SG01dVhnMWl1cTJ0d3pqVHMzNjdCZHI2dkNYckhoT01KVnRHRDFtdnhuRlB0VzkvU1UxWjZuamd4cQpFNnorL1o2RkVqZ2EvZ054b2tqWWlIMzRBQ3Z6bVBzMHlpKzcySERmTUJvekhkcU1CRGU3TkMrK0lTQ0JYUVU0ClB5TWRqQkg4UkhVQUJEdkoyd1A1UnEwRm0wUHVrbUtEMHdGVytjcEpZaGk1T2ducFRlVVYxSnY4L2NFYStvTVkKOGJoQkxsRGUrK09UWUJyNS9sVDIvY29Kd1lYTDQrbGFicmNkdWNONEFPK2Rhd1JjTy9MUmJCTmh3c3krWEswTQpGb3YwdlBYcklzM1dBQW0rS1d3YVh1VEEwRTBlS3FLU1dMa1hCcEVHWU1ibmFoNUVpKzJVeHQ4SWFneEVHbzlICmVXenB1aWhucmYwSDZPd3ViYS82bHNZWnQ3MDBmcU5qNEpoUFhPNDRBZ2RKQWdNQkFBRT0KLS0tLS1FTkQgUFVCTElDIEtFWS0tLS0tCg==' | base64 -d > /tmp/fn-pub.pem
echo "ADMINPASS:"; openssl pkeyutl -encrypt -pubin -inkey /tmp/fn-pub.pem -in $V/admin.pass | base64 -w0; echo
sleep 60; docker logs koha-test 2>&1 | tail -15
