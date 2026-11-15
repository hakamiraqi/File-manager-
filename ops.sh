cd /home/ubuntu/koha-test; tail -5 build.log; pgrep -fa "docker compose build" | head -2; docker images koha-test --format '{{.Repository}}:{{.Tag}} {{.Size}}'
