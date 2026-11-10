<?php
// One-off: adds the dental college's theses catalogue (as thesis folders) and the source-books
// catalogue (books + copies) for the main admin. Re-runnable: what is already there is skipped.
use App\Models\ActivityLog;
use App\Models\Folder;
use App\Models\Library\Book;
use App\Models\Library\BookCopy;
use App\Models\Library\LibrarySection;
use App\Models\User;
use App\Services\Theses\ThesisCatalog;
use App\Services\UniqueNamer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$dir = getenv('IMPORT_DIR');
$admin = User::where('email', 'admin@filenest.test')->firstOrFail();

// Theses: one thesis folder each, inside a shared parent folder so every signed-in reader sees the catalogue.
$theses = json_decode(file_get_contents("$dir/theses.json"), true);
$parent = Folder::firstOrCreate(['user_id' => $admin->id, 'parent_id' => null, 'name' => 'الرسائل والأطاريح — كلية طب الأسنان']);
$parent->forceFill(['is_public' => true])->save();
$existing = Folder::where('folder_type', 'thesis')->pluck('thesis_title')->map(fn ($t) => ThesisCatalog::normalize($t))->flip();
$t = ['added' => 0, 'skipped' => 0];
DB::transaction(function () use ($theses, $admin, $parent, &$existing, &$t) {
    foreach ($theses as $row) {
        $key = ThesisCatalog::normalize($row['thesis_title']);
        if (isset($existing[$key])) { $t['skipped']++; continue; }
        $existing[$key] = true;
        Folder::create(Folder::withInstitutionDefaults([
            'user_id' => $admin->id, 'parent_id' => $parent->id, 'folder_type' => 'thesis',
            'name' => UniqueNamer::forFolder($admin->id, $parent->id, Str::limit($row['thesis_title'], 120, '…')),
        ] + array_filter($row, fn ($v) => $v !== null && $v !== '')));
        $t['added']++;
    }
});
ActivityLog::record('thesis.imported', "استورد {$t['added']} رسالة من فهرس كلية طب الأسنان");

// Books: one book per row with its copies; barcodes LIB###### are given automatically.
$books = json_decode(file_get_contents("$dir/books.json"), true);
$b = ['added' => 0, 'copies' => 0, 'skipped' => 0];
DB::transaction(function () use ($books, $admin, &$b) {
    foreach ($books as $row) {
        $section = LibrarySection::fromPath($row['section']);
        $dup = Book::where('title', mb_substr($row['title'], 0, 255))->where('section_id', $section?->id)
            ->where('description', $row['description'])->exists();
        if ($dup) { $b['skipped']++; continue; }
        $book = Book::create([
            'section_id' => $section?->id, 'title' => mb_substr($row['title'], 0, 255), 'authors' => $row['authors'],
            'publisher' => $row['publisher'] ? mb_substr($row['publisher'], 0, 255) : null, 'year' => $row['year'],
            'language' => $row['language'], 'edition' => $row['edition'] ? mb_substr($row['edition'], 0, 50) : null,
            'pages' => $row['pages'], 'description' => $row['description'], 'created_by' => $admin->id,
        ]);
        for ($c = 0; $c < $row['copies']; $c++) {
            $book->copies()->create(['barcode' => BookCopy::newBarcodePlaceholder()]);
            $b['copies']++;
        }
        $b['added']++;
    }
});
ActivityLog::record('library.import', "استورد {$b['added']} كتاباً ({$b['copies']} نسخة) من فهرس الكتب المصدرية");
echo 'THESES: '.json_encode($t).'  BOOKS: '.json_encode($b)."\n";
echo 'TOTALS: theses='.Folder::where('folder_type', 'thesis')->count().' books='.Book::count().' copies='.BookCopy::count().' sections='.LibrarySection::count()."\n";
