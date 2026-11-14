docker exec filenest-app php artisan tinker --execute='
use App\Models\Library\Book;
$t = Book::count();
$cols = Schema::getColumnListing("books"); echo implode(",", array_intersect($cols, ["cover_path","cover_status","cover_source","cover_checked_at"]))."\n";
$with = Book::whereNotNull("cover_path")->where("cover_path","!=","")->count();
echo "total=$t with=$with without=".($t-$with)."\n";
foreach (Book::selectRaw("coalesce(cover_source,\"-\") s, coalesce(cover_status,\"-\") st, count(*) n")->groupBy("s","st")->orderByDesc("n")->get() as $r) echo $r->s." | ".$r->st." | ".$r->n."\n";
echo "copies=".App\Models\Library\BookCopy::count()."\n";
echo "with-copies-and-cover=".Book::whereNotNull("cover_path")->whereHas("copies")->count()."\n";
' 2>&1 | tail -20
