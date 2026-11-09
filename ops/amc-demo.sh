#!/bin/sh
# Runs on the VPS: check what the AMC exam's sheets look like right now (no rebuild).
set -e
P=$(sudo docker exec examnest-amc sh -c 'ls -d /data/projects/*-9 2>/dev/null || find / -maxdepth 4 -type d -name "*-9" 2>/dev/null | head -1' | head -1)
echo "project: $P"
sudo docker exec examnest-amc sh -c "ls -la --time-style=full-iso $P; grep -n -m3 'Font\|Lang\|LaTeX' $P/source.txt; grep -n 'HTTPS\|H2O' $P/source.txt | head -5"
sudo docker exec examnest-amc sh -c "cat $P/DOC-subject.pdf" > /tmp/subject.pdf
ls -la /tmp/subject.pdf
