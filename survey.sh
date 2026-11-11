#!/bin/bash
echo "ARCH $(uname -m) CPUS $(nproc)"; lscpu | grep -E "Model name|Flags" | cut -c1-200
free -m | head -2; df -h / /var/lib/docker 2>/dev/null | tail -2
docker ps --format '{{.Names}} {{.Status}}' | head -30
docker stats --no-stream --format '{{.Name}} {{.CPUPerc}} {{.MemUsage}}' | sort -k2 -rn | head -10
docker network ls --format '{{.Name}}' | head; docker inspect filenest-app --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}'
uptime
