#!/bin/bash
set -e
mkdir -p /opt/qari && cp /tmp/qari/docker-compose.yml /tmp/qari/fetch-models.sh /opt/qari/
echo "== image"; docker pull -q ghcr.io/ggml-org/llama.cpp:server; docker image inspect ghcr.io/ggml-org/llama.cpp:server --format '{{.Architecture}} {{.Created}}'
echo "== models"; bash /opt/qari/fetch-models.sh 2>&1 | grep -v "^  %\|^ *[0-9]" | tail -8
echo "== start"; cd /opt/qari && docker compose -p qari up -d 2>&1 | tail -3
for i in $(seq 1 60); do
  a=$(docker exec filenest-app sh -c 'curl -s -o /dev/null -w "%{http_code}" http://qari-2b:8080/health; echo -n " "; curl -s -o /dev/null -w "%{http_code}" http://qari-4b:8080/health' 2>/dev/null || true)
  echo "health $a"; [ "$a" = "200 200" ] && break; sleep 10
done
docker stats --no-stream --format '{{.Name}} {{.CPUPerc}} {{.MemUsage}}' qari-2b qari-4b
docker logs qari-4b 2>&1 | tail -5; df -h / | tail -1
