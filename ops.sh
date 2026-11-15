docker exec -u www-data filenest-app php artisan tinker --execute='
$d = app(App\Services\Library\DoiLookup::class)->find("https://doi.org/10.1016/j.joms.2017.11.020");
echo json_encode(array_intersect_key($d ?? [], array_flip(["title","authors","journal","year","volume","issue","page_range","type"])), JSON_UNESCAPED_UNICODE)."\n";
$b = new App\Models\Library\Book($d ?? []); $c = App\Services\Library\Citation::for($b);
echo $c->apa()["text"]."\n".$c->vancouver()["text"]."\n";
$d2 = app(App\Services\Library\DoiLookup::class)->find("10.5281/zenodo.1234");
echo "datacite: ".($d2["title"] ?? "none")." | ".($d2["type"] ?? "")."\n";
'
curl -s -o /dev/null -w "cite %{http_code}\n" "https://files.azoclinic.duckdns.org/library/books/1/cite"
