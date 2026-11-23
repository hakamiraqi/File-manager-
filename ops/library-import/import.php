<?php

// One-off: adds the dental college's theses and source books, and fills the Koha link settings
// (everything but the Koha username and password). Safe to run twice: rows already there are skipped.
// Run inside the app container: php /tmp/library-import/import.php /tmp/library-import

use App\Models\ActivityLog;
use App\Models\Folder;
use App\Models\Library\Book;
use App\Models\Library\BookCopy;
use App\Models\Library\LibrarySection;
use App\Models\Setting;
use App\Models\User;
use App\Services\Theses\ThesisCatalog;
use App\Services\UniqueNamer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

chdir('/var/www/html');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$dir = $argv[1] ?? __DIR__;
$admin = User::where('is_master_admin', true)->first() ?? User::where('role', 'admin')->firstOrFail();
Auth::login($admin);

$csv = function (string $file): array {
    $fh = fopen($file, 'r');
    $head = fgetcsv($fh, 0, ',', '"', '');
    $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', $head[0]);
    $rows = [];
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        if (count($r) === count($head)) {
            $rows[] = array_combine($head, array_map('trim', $r));
        }
    }

    return $rows;
};
$nz = fn ($v) => ($v === '' || $v === null) ? null : $v;

// ---- Theses: one thesis folder each, inside «الرسائل والأطاريح»
$parent = Folder::firstOrCreate(
    ['user_id' => $admin->id, 'parent_id' => null, 'name' => 'الرسائل والأطاريح'],
    ['folder_type' => 'files']
);
$existing = Folder::where('folder_type', 'thesis')->pluck('thesis_title')->map(fn ($t) => ThesisCatalog::normalize($t))->flip();
$added = $skipped = 0;
DB::transaction(function () use ($csv, $dir, $nz, $admin, $parent, &$existing, &$added, &$skipped) {
    foreach ($csv("$dir/theses-import.csv") as $r) {
        $title = $r['عنوان الرسالة'];
        $key = ThesisCatalog::normalize($title);
        if ($title === '' || isset($existing[$key])) {
            $skipped++;

            continue;
        }
        $existing[$key] = true;
        Folder::create(Folder::withInstitutionDefaults([
            'user_id' => $admin->id,
            'parent_id' => $parent->id,
            'name' => UniqueNamer::forFolder($admin->id, $parent->id, Str::limit($title, 120, '…')),
            'folder_type' => 'thesis',
            'thesis_title' => Str::limit($title, 255, ''),
            'researcher_name' => $nz($r['اسم الباحث']),
            'degree' => $r['الدرجة'] === 'دكتوراه' ? 'phd' : 'master',
            'university' => $nz($r['الجامعة']),
            'subject' => $nz($r['الموضوع العام']),
            'keywords' => $nz($r['الكلمات المفتاحية']),
            'publication_year' => $nz($r['سنة النشر']),
            'language' => $r['اللغة'] === 'العربية' ? 'ar' : 'en',
        ]));
        $added++;
    }
});
ActivityLog::record('thesis.imported', "استورد {$added} رسالة من فهرس كلية طب الأسنان");
echo "Theses: added {$added}, skipped {$skipped} (already there). Folder id {$parent->id}\n";

// ---- Koha link settings (the username and password are added by the user)
foreach ([
    'koha_url' => 'http://167.86.66.47:8080',
    'koha_auth' => 'basic',
    'koha_library' => 'DENT',
    'koha_match' => 'cardnumber',
    'koha_push' => '1',
    'koha_item_type' => 'BK',
    'koha_enabled' => '1',
] as $k => $v) {
    Setting::set($k, $v);
}
echo "Koha settings saved (enabled; waits for the username and password).\n";

// ---- Source books, with their copies; the register code goes on each copy's shelf
$books = $copies = $bskipped = 0;
DB::transaction(function () use ($csv, $dir, $nz, $admin, &$books, &$copies, &$bskipped) {
    foreach ($csv("$dir/books-import.csv") as $r) {
        $title = mb_substr($r['العنوان'], 0, 255);
        if ($title === '') {
            continue;
        }
        $code = $r['الرف'];
        $dup = Book::where('title', $title)->where('authors', $nz($r['المؤلف']))
            ->whereHas('copies', fn ($q) => $q->where('shelf', $nz($code)))->exists();
        if ($dup) {
            $bskipped++;

            continue;
        }
        $section = LibrarySection::fromPath($r['القسم']);
        $book = Book::create([
            'title' => $title,
            'authors' => $nz(mb_substr($r['المؤلف'], 0, 255)),
            'publisher' => $nz(mb_substr($r['الناشر'], 0, 255)),
            'year' => $nz(mb_substr($r['السنة'], 0, 10)),
            'edition' => $nz(mb_substr($r['الطبعة'], 0, 50)),
            'language' => $nz($r['اللغة']),
            'section_id' => $section?->id,
            'created_by' => $admin->id,
        ]);
        $books++;
        $n = max(1, min(100, (int) $r['عدد النسخ']));
        for ($c = 0; $c < $n; $c++) {
            $book->copies()->create(['barcode' => BookCopy::newBarcodePlaceholder(), 'shelf' => $nz($code)]);
            $copies++;
        }
    }
});
ActivityLog::record('library.import', "استورد {$books} كتاباً ({$copies} نسخة) من سجل الكتب المصدرية");
echo "Books: added {$books} with {$copies} copies, skipped {$bskipped} (already there).\n";
echo 'Totals now: theses '.Folder::where('folder_type', 'thesis')->count().', books '.Book::count().', copies '.BookCopy::count()."\n";
