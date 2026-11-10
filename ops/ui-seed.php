<?php
// Sample library for the presentation's pictures (throwaway SQLite on the runner).
use App\Models\Folder;
use App\Models\Library\Book;
use App\Models\Library\BookCopy;
use App\Models\Library\Hold;
use App\Models\Library\LibraryMember;
use App\Models\Library\LibrarySection;
use App\Models\Library\LibraryShelf;
use App\Models\Library\Loan;
use App\Models\Setting;
use App\Models\User;
use App\Services\Library\LibraryMap;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

$admin = User::create(['name' => 'أمين المكتبة', 'email' => 'ui@check.test', 'password' => Hash::make('ui-check-pass-1'), 'role' => 'admin', 'is_master_admin' => true,
    'can_manage_appearance' => true, 'can_manage_users' => true, 'can_manage_translations' => true, 'can_view_statistics' => true, 'can_manage_settings' => true, 'email_verified_at' => now()]);
$admin->forceFill(['email_verified_at' => now()])->save();
$reader = User::create(['name' => 'زينب علي', 'email' => 's1@check.test', 'password' => Hash::make('x'), 'role' => 'user', 'email_verified_at' => now()]);
$reader->forceFill(['email_verified_at' => now()])->save();

$tree = ['العلوم الطبية الأساسية' => ['التشريح', 'علم الأنسجة', 'الفسلجة'], 'طب الأسنان' => ['جراحة الفم', 'تقويم الأسنان', 'طب أسنان الأطفال'], 'المراجع العامة' => ['القواميس', 'الموسوعات'], 'اللغة الإنكليزية' => []];
$palette = [[79, 70, 229], [16, 185, 129], [245, 158, 11], [220, 38, 38], [124, 58, 237], [14, 165, 233], [234, 88, 12], [21, 128, 61]];
$cover = function (int $i) use ($palette) {
    [$r, $g, $b] = $palette[$i % count($palette)];
    $im = imagecreatetruecolor(300, 440);
    imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
    imagefilledrectangle($im, 0, 300, 300, 440, imagecolorallocate($im, (int) ($r * .6), (int) ($g * .6), (int) ($b * .6)));
    imagefilledrectangle($im, 30, 60, 270, 66, imagecolorallocate($im, 255, 255, 255));
    imagefilledrectangle($im, 30, 90, 200, 96, imagecolorallocate($im, 255, 255, 255));
    imagefilledellipse($im, 150, 200, 120, 120, imagecolorallocatealpha($im, 255, 255, 255, 80));
    ob_start(); imagepng($im); $png = ob_get_clean();
    $path = "library/covers/demo-{$i}.png";
    Storage::disk('local')->put($path, $png);
    return $path;
};
$sections = [];
foreach ($tree as $root => $kids) {
    $p = LibrarySection::create(['name' => $root, 'location' => 'القاعة الرئيسية']);
    $sections[] = $p;
    foreach ($kids as $k) $sections[] = LibrarySection::create(['name' => $k, 'parent_id' => $p->id]);
}
$titles = [
    ['Gray\'s Anatomy for Students', 'Richard Drake', 'التشريح', 'en'], ['التشريح السريري للأطراف', 'د. حسن الجبوري', 'التشريح', 'ar'], ['Netter Atlas of Human Anatomy', 'Frank Netter', 'التشريح', 'en'],
    ['Wheater\'s Functional Histology', 'Barbara Young', 'علم الأنسجة', 'en'], ['علم الأنسجة الفموية', 'د. سعاد كريم', 'علم الأنسجة', 'ar'],
    ['Guyton and Hall Physiology', 'John Hall', 'الفسلجة', 'en'], ['مبادئ الفسلجة الطبية', 'د. علي ناصر', 'الفسلجة', 'ar'],
    ['Contemporary Oral and Maxillofacial Surgery', 'James Hupp', 'جراحة الفم', 'en'], ['جراحة الفم الصغرى', 'د. مهدي عباس', 'جراحة الفم', 'ar'],
    ['Contemporary Orthodontics', 'William Proffit', 'تقويم الأسنان', 'en'], ['أساسيات تقويم الأسنان', 'د. رنا فاضل', 'تقويم الأسنان', 'ar'],
    ['Pediatric Dentistry', 'Arthur Nowak', 'طب أسنان الأطفال', 'en'], ['طب أسنان الأطفال السريري', 'د. هدى جاسم', 'طب أسنان الأطفال', 'ar'],
    ['قاموس المصطلحات الطبية', 'مجمع اللغة', 'القواميس', 'ar'], ['Oxford Medical Dictionary', 'Oxford', 'القواميس', 'en'], ['الموسوعة الطبية الحديثة', 'نخبة من الأطباء', 'الموسوعات', 'ar'],
    ['English for Dentistry', 'Ian Wood', 'اللغة الإنكليزية', 'en'], ['Medical English Grammar', 'Sam McCarter', 'اللغة الإنكليزية', 'en'],
];
$byName = collect($sections)->keyBy('name');
// The floor: one reading room with rows of bookcases, one per section.
app(LibraryMap::class)->saveFloor(['width' => 24, 'depth' => 16, 'entrance' => ['x' => 12, 'y' => 15.5], 'name' => 'المكتبة المركزية', 'storeys' => ['الطابق الأرضي'], 'separate_halls' => false]);
$shelfSections = ['التشريح', 'علم الأنسجة', 'الفسلجة', 'جراحة الفم', 'تقويم الأسنان', 'طب أسنان الأطفال', 'القواميس', 'الموسوعات', 'اللغة الإنكليزية'];
$codes = [];
foreach (['A', 'B', 'C'] as $r => $L) {
    foreach (range(1, 6) as $k) {
        $sec = $shelfSections[($r * 3 + intdiv($k - 1, 2)) % count($shelfSections)];
        $code = $L.$k;
        LibraryShelf::create(['code' => $code, 'kind' => 'shelf', 'storey' => 0, 'section_id' => $byName[$sec]->id, 'x' => 4 + ($k - 1) * 3.2, 'y' => 3 + $r * 3.6, 'rotation' => 0, 'width' => 2.6, 'depth' => 0.9, 'levels' => 5, 'double_sided' => true]);
        $codes[$sec][] = $code;
    }
}
LibraryShelf::create(['code' => 'D1', 'name' => 'مكتب الإعارة', 'kind' => 'desk', 'storey' => 0, 'x' => 19.5, 'y' => 13.8, 'rotation' => 0, 'width' => 2.4, 'depth' => 0.9, 'levels' => 1]);
LibraryShelf::create(['code' => 'T1', 'name' => 'طاولة قراءة', 'kind' => 'table', 'storey' => 0, 'x' => 5, 'y' => 13.5, 'rotation' => 0, 'width' => 2.4, 'depth' => 1.2, 'levels' => 1]);
LibraryShelf::create(['code' => 'T2', 'name' => 'طاولة قراءة', 'kind' => 'table', 'storey' => 0, 'x' => 9, 'y' => 13.5, 'rotation' => 0, 'width' => 2.4, 'depth' => 1.2, 'levels' => 1]);
LibraryShelf::create(['code' => 'G1', 'name' => 'بوابة الحماية', 'kind' => 'gate', 'storey' => 0, 'x' => 13.5, 'y' => 15.2, 'rotation' => 0, 'width' => 1.2, 'depth' => 0.4, 'levels' => 1]);

$members = [];
foreach ([['زينب علي', 'student', 'الثانية', $reader->id], ['أحمد كريم', 'student', 'الثالثة', null], ['حسين محمد', 'student', 'الرابعة', null], ['مريم حسن', 'student', 'الأولى', null], ['د. سعاد كريم', 'teacher', null, null], ['علي جاسم', 'staff', null, null], ['نور الهدى عباس', 'student', 'الخامسة', null], ['مصطفى قاسم', 'student', 'الثانية', null]] as $i => $m) {
    $members[] = LibraryMember::create(['user_id' => $m[3], 'name' => $m[0], 'card_number' => 'L'.(1001 + $i), 'type' => $m[1], 'stage' => $m[2], 'phone' => '0770000000'.$i, 'active' => true]);
}
$copies = [];
foreach ($titles as $i => $t) {
    $book = Book::create(['section_id' => $byName[$t[2]]->id, 'title' => $t[0], 'authors' => $t[1], 'isbn' => '978'.str_pad((string) (100000000 + $i * 7919), 10, '0', STR_PAD_LEFT), 'publisher' => $t[3] === 'en' ? 'Elsevier' : 'دار الكتب العلمية',
        'year' => 2015 + $i % 9, 'language' => $t[3], 'pages' => 300 + $i * 37, 'description' => 'كتاب منهجي للمرحلة الدراسية مع أمثلة سريرية.', 'cover_path' => $cover($i), 'reference_only' => $t[2] === 'الموسوعات', 'created_by' => $admin->id]);
    $sc = $codes[$t[2]] ?? ['A1'];
    foreach (range(1, 2 + $i % 3) as $c) {
        $copies[] = BookCopy::create(['book_id' => $book->id, 'barcode' => sprintf('B%05d', $i * 10 + $c), 'rfid_tag' => $c === 1 ? 'E200'.sprintf('%08d', $i * 10 + $c) : null, 'shelf' => $sc[$c % count($sc)].'-'.(1 + $c % 5), 'status' => BookCopy::AVAILABLE, 'condition' => 'good']);
    }
}
// Loans over the last months, some late, some returned with fines.
$n = 0;
foreach ($copies as $k => $copy) {
    if ($k % 3 === 2) continue;
    $m = $members[$k % count($members)];
    $issued = now()->subDays(5 + ($k * 11) % 150);
    $returned = $k % 4 === 0 ? null : $issued->copy()->addDays(7 + $k % 12);
    if (! $returned && $issued->lt(now()->subDays(40))) $returned = $issued->copy()->addDays(20);
    $late = $returned ? max(0, (int) $issued->copy()->addDays(14)->diffInDays($returned, false)) : 0;
    Loan::create(['copy_id' => $copy->id, 'member_id' => $m->id, 'issued_by' => $admin->id, 'received_by' => $returned ? $admin->id : null, 'issued_at' => $issued, 'due_at' => $issued->copy()->addDays(14), 'returned_at' => $returned,
        'renewals' => $k % 5 === 0 ? 1 : 0, 'fine_amount' => $late * 250, 'fine_paid_at' => $late && $k % 2 ? $returned : null]);
    if (! $returned) $copy->update(['status' => BookCopy::LOANED]);
    $n++;
}
BookCopy::where('status', BookCopy::AVAILABLE)->skip(5)->first()?->update(['status' => BookCopy::DAMAGED]);
// Zainab: two books at home (one due soon), a hold.
$z = $members[0];
foreach (BookCopy::where('status', BookCopy::AVAILABLE)->take(2)->get() as $j => $c) {
    Loan::create(['copy_id' => $c->id, 'member_id' => $z->id, 'issued_by' => $admin->id, 'issued_at' => now()->subDays(10 + $j * 8), 'due_at' => now()->subDays(10 + $j * 8)->addDays(14)]);
    $c->update(['status' => BookCopy::LOANED]);
}
$busy = Book::whereHas('copies', fn ($q) => $q->where('status', BookCopy::LOANED))->first();
Hold::create(['book_id' => $busy->id, 'member_id' => $z->id, 'status' => Hold::WAITING]);
// Theses
foreach ([['أثر المضمضة بالكلورهيكسيدين على اللويحة الجرثومية', 'أحمد علي حسن', 'master', 'د. سعاد كريم', 2023, 'طب الأسنان الوقائي'], ['تقييم دقة الأشعة المخروطية في تحديد قناة العصب السنخي', 'مريم فاضل', 'phd', 'د. حسن الجبوري', 2024, 'الأشعة'], ['انتشار تسوس الأسنان لدى أطفال المدارس في بغداد', 'علي جاسم محمد', 'master', 'د. هدى جاسم', 2022, 'طب أسنان الأطفال'], ['مقارنة مواد الحشو الضوئي في مقاومة الكسر', 'نور حسين', 'diploma', 'د. مهدي عباس', 2025, 'المعالجة التحفظية'], ['العلاقة بين أمراض اللثة وداء السكري', 'رسل أمير', 'master', 'د. علي ناصر', 2021, 'أمراض اللثة']] as $t) {
    Folder::create(['user_id' => $admin->id, 'name' => mb_substr($t[0], 0, 60), 'folder_type' => 'thesis', 'is_public' => true, 'thesis_title' => $t[0], 'researcher_name' => $t[1], 'degree' => $t[2], 'supervisor_name' => $t[3], 'publication_year' => $t[4], 'department' => $t[5], 'university' => 'جامعة بغداد', 'college' => 'كلية طب الأسنان', 'language' => 'ar']);
}
echo "seeded library: ".Book::count()." books, ".BookCopy::count()." copies, {$n} loans\n";
$f = Folder::create(['user_id' => $admin->id, 'name' => 'Anatomy — التشريح']);
foreach (['Netter Atlas of Human Anatomy 7th edition head and neck.pdf', 'Grays Anatomy for Students 4th Edition Full Book.pdf', 'التشريح السريري للرأس والرقبة الطبعة الثالثة كاملة.pdf', 'short.pdf'] as $n) {
    $p = $admin->id.'/'.Illuminate\Support\Str::uuid().'.pdf';
    Storage::disk(config('filenest.disk'))->put($p, '%PDF-1.4');
    App\Models\File::create(['user_id' => $admin->id, 'folder_id' => $f->id, 'name' => $n, 'disk' => config('filenest.disk'), 'path' => $p, 'extension' => 'pdf', 'mime_type' => 'application/pdf', 'size' => 8]);
}
Folder::create(['user_id' => $admin->id, 'parent_id' => $f->id, 'name' => 'Head and Neck Lectures Second Semester 2025-2026']);
echo "folder {$f->id}\n";
