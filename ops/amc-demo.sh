#!/bin/sh
# Runs on the VPS: the 100-question AMC exam, and its sheets on the AMC server.
set -e
sudo docker exec examnest-app php artisan exams:amc-demo --rebuild --no-ansi
