#!/bin/bash
mask() { sed -E 's/[A-Za-z0-9+\/_=-]{24,}/***/g'; }
for u in clinic-keepalive-load.timer clinic-keepalive-load.service; do echo "== $u"; systemctl cat $u 2>&1 | mask; done
echo "== status"; systemctl status clinic-keepalive-load.timer clinic-keepalive-load.service --no-pager 2>&1 | head -30 | mask
echo "== script"; f=$(systemctl show -p ExecStart --value clinic-keepalive-load.service | grep -oE 'path=[^ ;]+' | cut -d= -f2); for p in $f; do echo "-- $p"; head -60 "$p" 2>/dev/null | mask; done
echo "== python"; ps -eo pid,etime,pcpu,pmem,args | grep -E 'python3' | grep -v grep | cut -c1-200
