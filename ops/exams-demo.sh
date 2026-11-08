#!/bin/sh
# Runs on the VPS: makes the trial exam in the running exam system.
set -e
sudo docker exec examnest-app php artisan exams:demo --no-ansi > /tmp/exams-demo.out 2>&1 || { cat /tmp/exams-demo.out; exit 1; }
# The repository is public: no names or e-mail addresses in the log.
grep -v '^Student accounts' /tmp/exams-demo.out | sed -E 's/[^ ]+@[^ :]+/(student)/g'
echo "Student accounts: $(grep '^Student accounts' /tmp/exams-demo.out | sed -E 's/^Student accounts \(([0-9]+)\).*/\1/')"
rm -f /tmp/exams-demo.out
