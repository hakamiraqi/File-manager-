#!/bin/bash
# Qari 4B: no better on the trial pages, slower, holds ~7 GB and ran out of memory once; stopped
# (the trial page shows it as stopped). Start again with: docker start qari-4b
docker stop qari-4b >/dev/null && echo "STOPPED qari-4b"
docker update --restart=no qari-4b >/dev/null
free -m | head -2
docker exec filenest-app sh -c 'cat /tmp/qari-ar.log 2>/dev/null | tail -3; ls /tmp/qari-done.txt 2>/dev/null'
