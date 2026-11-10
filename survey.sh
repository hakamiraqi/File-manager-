#!/bin/bash
# Read-only: what runs on this server at night (public log: long tokens are masked).
mask() { sed -E 's/[A-Za-z0-9+\/_=-]{24,}/***/g; s#(://[^:/ ]+:)[^@ ]+@#\1***@#g'; }
echo "== crontab root"; sudo crontab -l 2>&1 | grep -v '^#' | mask
echo "== crontab $USER"; crontab -l 2>&1 | grep -v '^#' | mask
echo "== /etc/cron.d"; sudo grep -hv '^#' /etc/cron.d/* 2>/dev/null | grep . | mask
echo "== timers"; systemctl list-timers --all --no-pager 2>/dev/null | head -30
echo "== containers"; sudo docker ps --format '{{.Names}} | {{.Image}} | {{.Status}}'
echo "== top cpu now"; ps -eo pcpu,pmem,etime,comm --sort=-pcpu | head -15
echo "== container cpu"; sudo docker stats --no-stream --format '{{.Name}} {{.CPUPerc}} {{.MemUsage}}' | sort -k2 -rn | head -20
for c in $(sudo docker ps --format '{{.Names}}' | grep -E 'app$|scheduler$'); do
  if sudo docker exec "$c" test -f /var/www/html/artisan 2>/dev/null; then
    echo "== schedule:list in $c"; sudo docker exec -u www-data -w /var/www/html "$c" php artisan schedule:list 2>&1 | head -40 | mask
  fi
done
