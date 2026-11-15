docker exec filenest-app php artisan tinker --execute='
$b = App\Models\Library\Book::with("copies")->whereNull("marc_xml")->where("title","REGEXP","[ء-ي]")->first() ?? App\Models\Library\Book::with("copies")->whereNull("marc_xml")->first();
$r = App\Services\Library\Marc\MarcMapper::fromBook($b);
echo "leader18=".$r->leader[18]."\n";
foreach (["040","100","245","264","300","336","337","338"] as $t) foreach ($r->all($t) as $f) echo $t." ".json_encode($f["subfields"] ?? [], JSON_UNESCAPED_UNICODE)."\n";
'
