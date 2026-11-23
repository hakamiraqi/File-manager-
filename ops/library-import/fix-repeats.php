<?php

// Follow-up to import.php: register rows that repeat an earlier row (same title, author, no code)
// were skipped. Their copies go onto the existing book; the 10th edition of «Core Concepts in Health»
// is its own book. Safe to run twice (it marks itself done in the settings).
use App\Models\Library\Book;
use App\Models\Library\BookCopy;
use App\Models\Setting;

chdir('/var/www/html');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (Setting::get('ops_library_repeats_done')) {
    exit("Already done.\n");
}
$add = [
    ['Physical Examination and health Assessment', 'Carolyn Jarvis', 12],
    ["Cohen's Pathways of the PULP", 'LOUIS H.BERMAN, KENNETH M.HARGREAVES', 2],
    ["SUMMITT'S FUNDAMENTALS OF OPERATIVE DENTISTRY A Contemporary Approach", 'Thomas J.Hilton, Jack L.Ferracane', 2],
    ['performing advanced procedures', 'Stanley Loeb', 1],
    ['Roxburghs common skin diseases', 'R.Marks', 1],
];
$n = 0;
foreach ($add as [$title, $author, $copies]) {
    $book = Book::where('title', $title)->where('authors', $author)->firstOrFail();
    for ($i = 0; $i < $copies; $i++) {
        $book->copies()->create(['barcode' => BookCopy::newBarcodePlaceholder()]);
        $n++;
    }
}
$core = Book::where('title', 'CORE CONCEPTS IN HEALTH')->firstOrFail();
$new = Book::create(['title' => $core->title, 'authors' => $core->authors, 'year' => '2006', 'edition' => '10th',
    'language' => $core->language, 'section_id' => $core->section_id, 'created_by' => $core->created_by]);
$new->copies()->create(['barcode' => BookCopy::newBarcodePlaceholder()]);
$n++;
Setting::set('ops_library_repeats_done', now()->toIso8601String());
echo "Added {$n} copies and 1 book. Totals now: books ".Book::count().', copies '.BookCopy::count()."\n";
