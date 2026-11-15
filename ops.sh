docker network connect filenest_default koha-test 2>/dev/null; sleep 2
docker exec filenest-app sh -c 'curl -s -m 15 "http://koha-test:7090/biblios?version=1.1&operation=searchRetrieve&query=dc.title=surgery&maximumRecords=1&recordSchema=marcxml" | grep -o "<[a-z]*:\{0,1\}numberOfRecords>[0-9]*" | head -1' 2>&1
grep -n "networks" -A3 /home/ubuntu/koha-test/docker-compose.yml | head
