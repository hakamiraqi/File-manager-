#!/bin/sh
# Runs on the VPS: build the AMC exam's sheets again, and copy them out to look at.
set -e
sudo docker exec examnest-app php artisan exams:amc-demo --rebuild --no-ansi
sleep 5
for i in $(seq 60); do sudo docker exec examnest-amc sh -c 'cat /data/*-9/status.json' | grep -q '"built"' && break; sleep 5; done
P=$(sudo docker exec examnest-amc sh -c 'ls -d /data/*-9 | head -1')
echo "project: $P"
sudo docker exec examnest-amc sh -c "ls -la --time-style=full-iso $P; grep -n -m3 'Font\|Lang\|LaTeX' $P/*.txt; grep -n 'HTTPS\|H2O' $P/*.txt | head -5"
sudo docker exec examnest-amc sh -c "cat $P/subject.pdf" > /tmp/subject.pdf
ls -la /tmp/subject.pdf
sudo docker exec examnest-amc sh -c "sed -n '1,60p' $P/source_filtered.tex | grep -v '^%'; grep -n -m2 'HTTPS' $P/source_filtered.tex"
