echo "== res"; nproc; free -m | head -3; df -h / | tail -1; uname -m
docker stats --no-stream --format '{{.Name}} {{.MemUsage}}' 2>/dev/null | sort -k2 -h | tail -8
echo "== caddy"; docker inspect caddy --format '{{.HostConfig.NetworkMode}} {{range .Mounts}}{{.Source}}->{{.Destination}} {{end}}'
docker inspect caddy-api --format '{{.HostConfig.NetworkMode}} {{range .Mounts}}{{.Source}}->{{.Destination}} {{end}}'
for c in caddy caddy-api; do echo "--- $c"; docker exec $c sh -c 'ls /etc/caddy; cat /etc/caddy/Caddyfile' 2>/dev/null | grep -vE "^\s*#" | head -60; done
