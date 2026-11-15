docker ps -a --filter name=koha-test --format '{{.Status}}'; docker logs koha-test 2>&1 | grep -viE "^\.$" | head -60 | cut -c1-200
ls -la /var/lib/docker/volumes/koha-test_koha_setup/_data
