#!/bin/sh
# Runs on the VPS: check what the AMC exam's sheets look like right now (no rebuild).
set -e
P=$(sudo docker exec examnest-amc sh -c 'ls -d /data/*-9 | head -1')
echo "project: $P"
sudo docker exec examnest-amc sh -c "ls -la --time-style=full-iso $P; grep -n -m3 'Font\|Lang\|LaTeX' $P/*.txt; grep -n 'HTTPS\|H2O' $P/*.txt | head -5"
sudo docker exec examnest-amc sh -c "cat $P/subject.pdf" > /tmp/subject.pdf
ls -la /tmp/subject.pdf
sudo docker exec examnest-amc sh -c "sed -n '1,60p' $P/source_filtered.tex | grep -v '^%'; grep -n -m2 'HTTPS' $P/source_filtered.tex"
