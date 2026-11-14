pgrep -af "docker pull" | head -3; pkill -f "docker pull" || true
docker images --format '{{.Repository}}:{{.Tag}} {{.Size}}' | grep -i -E "euro|onlyoffice"
timeout 60 curl -sS -o /dev/null -w 'ghcr %{http_code} %{time_total}s\n' https://ghcr.io/v2/ || echo "ghcr unreachable"
timeout 30 docker manifest inspect ghcr.io/euro-office/documentserver:v9.3.7 2>&1 | head -5
nohup sh -c 'docker pull ghcr.io/euro-office/documentserver:v9.3.7 > /tmp/euro-pull.log 2>&1; echo PULL-EXIT=$? >> /tmp/euro-pull.log' >/dev/null 2>&1 &
sleep 45; tail -c 600 /tmp/euro-pull.log
