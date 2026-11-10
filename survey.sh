#!/bin/bash
# User, 11 Nov 02:28: stop tonight's keep-alive load only; the timer stays on for tomorrow.
systemctl stop clinic-keepalive-load.service
sleep 3
echo "SERVICE: $(systemctl is-active clinic-keepalive-load.service)"
echo "TIMER: $(systemctl is-enabled clinic-keepalive-load.timer) / $(systemctl is-active clinic-keepalive-load.timer)"
ps -eo pcpu,pmem,comm --sort=-pcpu | head -6
free -m | head -2
uptime
