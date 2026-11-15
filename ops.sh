echo "== res"; nproc; free -m | head -2; df -h / | tail -1; docker stats --no-stream --format '{{.Name}} {{.MemUsage}}' 2>/dev/null
echo "== ports"; ss -ltnp 2>/dev/null | awk '{print $4, $6}' | grep -E ":(80|443|8080|8081|3306) " | head
echo "== compose"; docker ps --format '{{.Names}} {{.Image}} {{.Ports}}'
ls /opt /srv 2>/dev/null; docker inspect filenest-web --format '{{range .Mounts}}{{.Source}} -> {{.Destination}}{{"\n"}}{{end}}' 2>/dev/null | head
echo "== dns"; getent hosts koha.azoclinic.duckdns.org files.azoclinic.duckdns.org opac.azoclinic.duckdns.org; curl -s -m 5 ifconfig.me; echo
echo "== certs"; ls /etc/letsencrypt/live 2>/dev/null; which certbot caddy 2>/dev/null
