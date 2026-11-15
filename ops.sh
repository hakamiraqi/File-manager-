cd /home/ubuntu/koha-test
chmod 755 entrypoint.sh
docker cp -a entrypoint.sh koha-test:/setup/entrypoint.sh
docker start koha-test >/dev/null; sleep 50
docker ps --filter name=koha-test --format '{{.Status}}'
docker logs --since 55s koha-test 2>&1 | tail -6
docker exec koha-test grep -c "127.0.0.1:11211" /etc/koha/sites/kohadent/koha-conf.xml
curl -s -o /dev/null -w "staff %{http_code}\n" -m 20 https://koha.azoclinic.duckdns.org/cgi-bin/koha/mainpage.pl
curl -s -o /dev/null -w "opac %{http_code}\n" -m 20 https://opac.azoclinic.duckdns.org/
curl -s -m 20 https://koha.azoclinic.duckdns.org/api/v1/ | head -c 150; echo
