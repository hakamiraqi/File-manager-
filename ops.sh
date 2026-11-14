cd /tmp
docker exec filenest-app php artisan migrate:status 2>/dev/null | grep marc_columns
docker exec filenest-app php artisan tinker --execute='
$r = app(App\Services\Library\Marc\SruClient::class)->search("9780132350884");
echo "results=".count($r["results"])." errors=".json_encode($r["errors"])."\n";
foreach (array_slice($r["results"],0,3) as $x) echo $x["server"]." | ".$x["book"]["title"]." | ".$x["book"]["authors"]." | ".$x["book"]["year"]." | ".$x["book"]["call_number"]."\n";
$r = app(App\Services\Library\Marc\SruClient::class)->search(null, "oral surgery", "peterson");
echo "title-search=".count($r["results"])." errors=".json_encode($r["errors"])."\n";
foreach (array_slice($r["results"],0,3) as $x) echo $x["server"]." | ".$x["book"]["title"]." | ".$x["book"]["year"]."\n";
$b = App\Models\Library\Book::with("copies")->first();
if ($b) { $iso = App\Services\Library\Marc\MarcWriter::iso(App\Services\Library\Marc\MarcMapper::fromBook($b)); $back = App\Services\Library\Marc\MarcReader::iso($iso); echo "export ok: ".$back->sub("245","a")." copies=".count($back->all("952"))."\n"; }
echo "books=".App\Models\Library\Book::count()."\n";
' 2>&1 | tail -15
