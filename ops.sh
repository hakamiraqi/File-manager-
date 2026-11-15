set -e
python3 -c "import json;d=json.load(open('/tmp/spec.json'));print('version',d['info'].get('version'));print('checkins' , '/checkins' in d['paths'], 'checkouts', '/checkouts' in d['paths'], 'biblios list', 'get' in d['paths'].get('/biblios',{}))"
# FileNest catalogue → MARC21 with copies at DENT
docker exec -u www-data filenest-app php artisan tinker --execute='
$fh = fopen("/tmp/fn.mrc","w"); $n=0; $c=0;
App\Models\Library\Book::with("copies")->chunkById(200, function ($books) use ($fh, &$n, &$c) { foreach ($books as $b) { fwrite($fh, App\Services\Library\Marc\MarcWriter::iso(App\Services\Library\Marc\MarcMapper::fromBook($b, "BK", "DENT"))); $n++; $c += $b->copies->count(); } });
fclose($fh); echo "books=$n copies=$c\n";'
docker cp filenest-app:/tmp/fn.mrc /tmp/fn.mrc && docker exec filenest-app rm -f /tmp/fn.mrc
docker cp /tmp/fn.mrc koha-test:/tmp/fn.mrc && docker exec koha-test chmod 644 /tmp/fn.mrc
docker exec koha-test koha-shell kohadent -c "perl /usr/share/koha/bin/migration_tools/bulkmarcimport.pl --biblios --file /tmp/fn.mrc -m ISO2709 --commit 100 2>&1 | tail -8"
docker exec koha-test koha-rebuild-zebra -f -b kohadent 2>&1 | tail -2
docker exec koha-test koha-mysql kohadent -e "select count(*) biblios from biblio; select count(*) items from items; select homebranch, itype, count(*) from items group by 1,2;"
