nproc; free -m | head -2; df -h / | tail -1; uname -m; docker network ls --format '{{.Name}}'; docker inspect headscale --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}'
