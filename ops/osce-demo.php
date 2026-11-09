<?php
// One-off, run inside the exam app container: a published trial OSCE (dental practical) exam.
// No names or e-mail addresses are printed (the repository and its logs are public).
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Exams\Exam;
use App\Models\Exams\Group;
use App\Models\Exams\OsceStation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

const TITLE = 'امتحان OSCE تجريبي — طب الأسنان';
$owner = User::where('email', 'admin@exams.test')->first() ?? User::where('role', 'admin')->orderBy('id')->first();
if (! $owner) { fwrite(STDERR, "No admin account.\n"); exit(1); }

Exam::where('user_id', $owner->id)->get()->filter(fn ($e) => ($e->title['ar'] ?? '') === TITLE)->each->delete();
Group::where('user_id', $owner->id)->where('name', 'مجموعة OSCE التجريبية')->get()->each->delete();

$stations = [
    ['أخذ التاريخ المرضي — مريض بألم سن (History taking)', 8, [
        ['يعرّف بنفسه ويتأكد من هوية المريض ويأخذ موافقته', 1],
        ['يسأل عن الشكوى الرئيسية بكلمات المريض', 1],
        ['يحلّل الألم: المكان، البداية، الشدة، النوع، الانتشار (SOCRATES)', 3],
        ['يسأل عن العوامل المثيرة والمخففة (بارد، حار، العض، الليل)', 2],
        ['يأخذ التاريخ الطبي: الأمراض المزمنة، الأدوية، الحساسية', 2],
        ['يسأل عن التاريخ السني السابق وعادات التنظيف والتدخين', 1],
        ['يلخّص للمريض ويذكر تشخيصاً مبدئياً معقولاً (التهاب لب)', 2],
        ['تواصل واضح ومتعاطف مع المريض', 1],
    ]],
    ['الفحص السريري للفم وتدوين مخطط الأسنان (Charting)', 8, [
        ['يغسل يديه ويلبس القفازات والكمامة قبل الفحص', 1],
        ['يفحص خارج الفم: الوجه، الغدد اللمفاوية، المفصل الفكي الصدغي', 2],
        ['يفحص الأنسجة الرخوة داخل الفم بترتيب منهجي', 2],
        ['يفحص كل الأسنان بالمرآة والمسبر بالترتيب', 2],
        ['يدوّن المخطط بالترقيم الصحيح (FDI)', 2],
        ['يسجّل التسوس والحشوات والأسنان المفقودة بالرموز الصحيحة', 2],
        ['يقيس عمق الجيوب اللثوية (BPE) ويسجله', 2],
    ]],
    ['التخدير الموضعي — حقنة العصب السنخي السفلي (IANB) على مجسّم', 8, [
        ['يراجع التاريخ الطبي والحساسية قبل التخدير', 1],
        ['يجهّز السرنجة والإبرة والأمبولة بطريقة صحيحة', 2],
        ['يحدّد العلامات التشريحية: الثلمة التاجية والرباط الجناحي الفكي', 2],
        ['يدخل الإبرة من الجهة المقابلة (الضواحك) بالعمق الصحيح', 2],
        ['يسحب للتأكد (Aspiration) قبل الحقن', 2],
        ['يحقن ببطء ويراقب المريض', 1],
        ['يغطي الإبرة بطريقة اليد الواحدة ويتخلص منها بأمان', 2],
    ]],
    ['ضبط العدوى — غسل اليدين ولبس معدات الوقاية', 6, [
        ['يزيل الخواتم والساعة ويبلّل اليدين', 1],
        ['يغسل بالخطوات الست كاملة لمدة لا تقل عن 40 ثانية', 3],
        ['يجفف بمنشفة ورقية ويغلق الحنفية بها', 1],
        ['يلبس معدات الوقاية بالترتيب: الرداء، الكمامة، النظارة، القفازات', 3],
        ['يخلعها بالترتيب الصحيح دون تلويث نفسه', 2],
    ]],
    ['الإنعاش القلبي الرئوي الأساسي (BLS) على مجسّم', 6, [
        ['يتأكد من أمان المكان ويفحص الاستجابة', 1],
        ['يطلب المساعدة وجهاز مزيل الرجفان (AED)', 1],
        ['يفحص التنفس خلال 10 ثوانٍ', 1],
        ['يضغط بالمكان الصحيح بعمق 5–6 سم ومعدل 100–120 بالدقيقة', 3],
        ['نسبة 30 ضغطة إلى نفسين مع فتح مجرى الهواء', 2],
        ['يستخدم جهاز AED بالشكل الصحيح ويبتعد عند الصدمة', 2],
    ]],
];

$exam = DB::transaction(function () use ($owner, $stations) {
    $exam = Exam::create([
        'user_id' => $owner->id, 'title' => ['ar' => TITLE, 'en' => 'Trial OSCE — Dentistry'],
        'description' => ['ar' => 'امتحان عملي تجريبي من 5 محطات. يتنقّل الطالب بين المحطات ويقيّمه المقيِّم على قائمة التقييم. لا يُحتسب.',
            'en' => 'A trial practical exam of 5 stations. Each candidate rotates through them and the assessor marks the checklist. It does not count.'],
        'subject' => 'طب الأسنان', 'stage' => 'الكل', 'languages' => ['ar', 'en'], 'direction' => 'auto',
        'duration_minutes' => array_sum(array_column($stations, 1)), 'max_attempts' => 1, 'grading_method' => 'highest', 'pass_mark' => 60,
        'show_results' => 'after_submit', 'show_answers' => false, 'is_published' => true,
    ]);
    $exam->forceFill(['is_osce' => true])->save();
    foreach ($stations as $i => [$name, $minutes, $items]) {
        OsceStation::create(['exam_id' => $exam->id, 'position' => $i, 'name' => $name, 'minutes' => $minutes, 'assessor_id' => null,
            'checklist' => array_map(fn ($c) => ['item' => $c[0], 'points' => (float) $c[1]], $items)]);
    }
    $students = User::where('role', '!=', 'admin')->where('is_examiner', false)->pluck('id');
    $group = Group::create(['user_id' => $owner->id, 'name' => 'مجموعة OSCE التجريبية', 'stage' => 'الكل', 'department' => 'طب الأسنان']);
    $group->members()->sync($students);
    $exam->groups()->sync([$group->id]);

    return $exam;
});

echo "Trial OSCE #{$exam->id}: {$exam->osceStations()->count()} stations, {$exam->maxScore()} points, {$exam->roster()->count()} candidates.\n";
