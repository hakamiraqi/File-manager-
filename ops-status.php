<?php
use App\Models\Library\Book;
echo "BOOKS: ".Book::count()." WITH COVER: ".Book::whereNotNull('cover_path')->count()."\n";
echo "BOOK STATUS: ".json_encode(Book::query()->selectRaw("coalesce(cover_status,'-') s, count(*) c")->groupBy('s')->pluck('c','s'))."\n";
