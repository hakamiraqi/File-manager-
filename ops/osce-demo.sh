#!/bin/sh
# Runs on the VPS: makes the trial OSCE exam in the running exam system.
set -e
sudo docker exec -i examnest-app php < /tmp/osce-demo.php
rm -f /tmp/osce-demo.php
