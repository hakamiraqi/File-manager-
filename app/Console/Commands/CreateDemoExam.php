<?php

namespace App\Console\Commands;

use App\Http\Controllers\Exams\ManageController;
use App\Models\Exams\BankCategory;
use App\Models\Exams\Choice;
use App\Models\Exams\Exam;
use App\Models\Exams\Override;
use App\Models\Exams\Question;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A published trial exam with one question of every kind (and a bank category for the random
 * slot), for trying the system. The student accounts get it like every published exam; with
 * --student the named one also gets extra attempts so it can be tried more than once.
 */
class CreateDemoExam extends Command
{
    protected $signature = 'exams:demo {--student=* : Students who get 5 attempts} {--fresh : Replace an earlier trial exam}';

    protected $description = 'Create a trial exam with every question type';

    public const TITLE = 'امتحان تجريبي — كل أنماط الأسئلة';

    public function handle(): int
    {
        $owner = User::where('email', 'admin@exams.test')->first() ?? User::where('role', 'admin')->orderBy('id')->first();
        if (! $owner) {
            $this->error('No admin account.');

            return self::FAILURE;
        }
        Auth::setUser($owner);

        $old = Exam::where('user_id', $owner->id)->get()->filter(fn (Exam $e) => ($e->title['ar'] ?? '') === self::TITLE);
        if ($old->isNotEmpty() && ! $this->option('fresh')) {
            $this->info("Trial exam already there (#{$old->first()->id}); use --fresh to replace it.");
            $this->students($old->first());

            return self::SUCCESS;
        }
        $old->each->delete();

        $exam = DB::transaction(function () use ($owner) {
            $category = BankCategory::firstOrCreate(['user_id' => $owner->id, 'name' => 'بنك تجريبي — عواصم'], ['subject' => 'جغرافيا', 'is_shared' => true]);
            if ($category->questions()->count() < 3) {
                foreach ([['العراق', 'بغداد', 'البصرة'], ['مصر', 'القاهرة', 'الإسكندرية'], ['الأردن', 'عمّان', 'إربد'], ['لبنان', 'بيروت', 'طرابلس']] as [$country, $right, $wrong]) {
                    $q = Question::create(['exam_id' => null, 'bank_category_id' => $category->id, 'type' => 'single', 'body' => ['ar' => "ما عاصمة {$country}؟"], 'points' => 1]);
                    Choice::create(['exam_question_id' => $q->id, 'body' => ['ar' => $right], 'is_correct' => true, 'position' => 0]);
                    Choice::create(['exam_question_id' => $q->id, 'body' => ['ar' => $wrong], 'is_correct' => false, 'position' => 1]);
                }
            }

            $exam = Exam::create([
                'user_id' => $owner->id, 'title' => ['ar' => self::TITLE, 'en' => 'Trial exam — every question type'],
                'description' => ['ar' => 'امتحان للتجربة فيه سؤال من كل نمط. لا يُحتسب.', 'en' => 'A trial exam with one question of each kind. It does not count.'],
                'subject' => 'تجربة', 'stage' => 'الكل', 'languages' => ['ar', 'en'], 'direction' => 'auto',
                'duration_minutes' => 30, 'max_attempts' => 3, 'grading_method' => 'highest', 'pass_mark' => 50,
                'show_results' => 'after_submit', 'show_answers' => true, 'is_published' => true,
            ]);

            $questions = ManageController::checkQuestions($this->questions($category), ['ar', 'en']);
            foreach ($questions as $i => $q) {
                $question = Question::create([
                    'exam_id' => $exam->id, 'type' => $q['type'], 'body' => $q['body'], 'feedback' => $q['feedback'] ?: null,
                    'points' => $q['type'] === Question::DESCRIPTION ? 0 : $q['points'], 'settings' => $q['settings'] ?: null, 'position' => $i,
                ]);
                foreach ($q['choices'] as $j => $c) {
                    Choice::create(['exam_question_id' => $question->id, 'body' => $c['body'], 'match' => $c['match'] ?: null, 'is_correct' => $c['is_correct'], 'position' => $j]);
                }
            }

            return $exam;
        });

        $this->info("Trial exam #{$exam->id}: {$exam->questions()->count()} questions, {$exam->maxScore()} points.");
        $this->line('Types: '.$exam->questions()->pluck('type')->implode(', '));
        $this->students($exam);

        return self::SUCCESS;
    }

    /** Lists the student accounts (who see every published exam) and gives the named ones more attempts. */
    private function students(Exam $exam): void
    {
        $students = User::where('role', '!=', 'admin')->where('is_examiner', false)->orderBy('id')->get(['id', 'name', 'email']);
        $this->line('Student accounts ('.$students->count().'): '.$students->map(fn ($u) => "{$u->name} <{$u->email}>")->implode('; '));
        $named = $this->option('student') ?: ($students->count() === 1 ? [$students->first()->email] : []);
        foreach ($named as $email) {
            $user = User::where('email', $email)->first();
            if (! $user) {
                $this->warn("No account {$email}.");

                continue;
            }
            Override::updateOrCreate(['exam_id' => $exam->id, 'user_id' => $user->id], ['max_attempts' => 5, 'reason' => 'حساب التجربة']);
            $this->info("Linked {$user->email}: 5 attempts.");
        }
    }

    private function questions(BankCategory $category): array
    {
        $c = fn (string $ar, string $en, bool $right = false, ?array $match = null) => ['body' => ['ar' => $ar, 'en' => $en], 'is_correct' => $right, 'match' => $match];

        return [
            ['type' => 'description', 'body' => ['ar' => "اقرأ قبل البدء:\nفي هذا الامتحان سؤال واحد من كل نمط. أجب عنها كلها ثم سلّم لترى التصحيح.", 'en' => "Before you start:\nThis exam has one question of each kind. Answer them all, then submit to see the marking."]],
            ['type' => 'single', 'points' => 1, 'body' => ['ar' => 'ما الرمز الكيميائي للماء؟', 'en' => 'What is the chemical formula of water?'],
                'feedback' => ['ar' => 'ذرتا هيدروجين وذرة أكسجين.', 'en' => 'Two hydrogen atoms and one oxygen atom.'],
                'choices' => [$c('H2O', 'H2O', true), $c('CO2', 'CO2'), $c('NaCl', 'NaCl'), $c('O2', 'O2')]],
            ['type' => 'multiple', 'points' => 2, 'body' => ['ar' => 'أيّ هذه غازات نبيلة؟', 'en' => 'Which of these are noble gases?'],
                'choices' => [$c('هيليوم', 'Helium', true), $c('نيون', 'Neon', true), $c('حديد', 'Iron'), $c('أرغون', 'Argon', true)]],
            ['type' => 'true_false', 'points' => 1, 'body' => ['ar' => 'يغلي الماء عند 100 درجة مئوية عند مستوى سطح البحر.', 'en' => 'Water boils at 100 °C at sea level.'],
                'choices' => [$c('صح', 'True', true), $c('خطأ', 'False')]],
            ['type' => 'short', 'points' => 1, 'body' => ['ar' => 'ما عاصمة العراق؟', 'en' => 'What is the capital of Iraq?'], 'settings' => ['answers' => ['بغداد', 'Baghdad']]],
            ['type' => 'numeric', 'points' => 1, 'body' => ['ar' => 'اكتب قيمة π لمنزلتين عشريتين.', 'en' => 'Write π to two decimal places.'], 'settings' => ['answer' => '3.14', 'tolerance' => '0.01']],
            ['type' => 'matching', 'points' => 2, 'body' => ['ar' => 'طابق كل عنصر برمزه.', 'en' => 'Match each element with its symbol.'],
                'choices' => [$c('Na', 'Na', false, ['ar' => 'صوديوم', 'en' => 'Sodium']), $c('K', 'K', false, ['ar' => 'بوتاسيوم', 'en' => 'Potassium']), $c('Fe', 'Fe', false, ['ar' => 'حديد', 'en' => 'Iron'])]],
            ['type' => 'ordering', 'points' => 2, 'body' => ['ar' => 'رتّب الكواكب من الأقرب إلى الشمس.', 'en' => 'Order the planets from the closest to the Sun.'],
                'choices' => [$c('عطارد', 'Mercury'), $c('الزهرة', 'Venus'), $c('الأرض', 'Earth'), $c('المريخ', 'Mars')]],
            ['type' => 'cloze', 'points' => 2, 'body' => ['ar' => 'عاصمة العراق [[بغداد]]، وأكبر موانئه في [[*البصرة|الموصل|أربيل]].', 'en' => 'The capital of Iraq is [[Baghdad]], and its biggest port is in [[*Basra|Mosul|Erbil]].']],
            ['type' => 'classify', 'points' => 2, 'body' => ['ar' => 'صنّف كل غذاء: فاكهة أم خضار؟', 'en' => 'Fruit or vegetable?'],
                'choices' => [$c('تفاح', 'Apple', false, ['ar' => 'فاكهة', 'en' => 'Fruit']), $c('موز', 'Banana', false, ['ar' => 'فاكهة', 'en' => 'Fruit']),
                    $c('جزر', 'Carrot', false, ['ar' => 'خضار', 'en' => 'Vegetable']), $c('خيار', 'Cucumber', false, ['ar' => 'خضار', 'en' => 'Vegetable'])]],
            ['type' => 'drag_text', 'points' => 2, 'body' => ['ar' => 'يتكوّن الماء من [[الهيدروجين]] و[[الأكسجين]].', 'en' => 'Water is made of [[hydrogen]] and [[oxygen]].'],
                'settings' => ['distractors' => ['النيتروجين', 'الكربون', 'nitrogen', 'carbon']]],
            ['type' => 'drag_image', 'points' => 2, 'body' => ['ar' => 'اسحب اسم كل لون إلى دائرته.', 'en' => 'Drag each colour name onto its circle.'],
                'settings' => ['image' => $this->picture(), 'zones' => [['x' => 8, 'y' => 30, 'w' => 20, 'h' => 40], ['x' => 40, 'y' => 30, 'w' => 20, 'h' => 40], ['x' => 72, 'y' => 30, 'w' => 20, 'h' => 40]]],
                'choices' => [$c('أحمر', 'Red'), $c('أخضر', 'Green'), $c('أزرق', 'Blue')]],
            ['type' => 'audio', 'points' => 2, 'body' => ['ar' => 'سجّل بصوتك جملة تعرّف فيها بنفسك.', 'en' => 'Record a sentence introducing yourself.'], 'settings' => ['max_seconds' => 60]],
            ['type' => 'drawing', 'points' => 2, 'body' => ['ar' => 'ارسم مثلثاً قائم الزاوية وسمِّ أضلاعه.', 'en' => 'Draw a right triangle and name its sides.']],
            ['type' => 'hotspot', 'points' => 1, 'body' => ['ar' => 'اضغط على الدائرة الخضراء.', 'en' => 'Click the green circle.'],
                'settings' => ['image' => $this->picture(), 'zones' => [['x' => 40, 'y' => 30, 'w' => 20, 'h' => 40]]]],
            ['type' => 'calculated', 'points' => 2, 'body' => ['ar' => 'سيارة سرعتها {v} م/ث تسير {t} ثانية. كم متراً تقطع؟', 'en' => 'A car at {v} m/s drives for {t} s. How many metres does it go?'],
                'settings' => ['vars' => [['name' => 'v', 'min' => 10, 'max' => 30, 'decimals' => 0], ['name' => 't', 'min' => 5, 'max' => 20, 'decimals' => 0]], 'formula' => 'v * t', 'tolerance' => '0']],
            ['type' => 'essay', 'points' => 3, 'body' => ['ar' => 'اشرح باختصار لماذا تتغيّر فصول السنة.', 'en' => 'Explain briefly why the seasons change.'], 'settings' => ['min_words' => 10, 'max_words' => 200]],
            ['type' => 'upload', 'points' => 2, 'body' => ['ar' => 'ارفع صورة أو ملف PDF لأي ورقة كتبتها بخط يدك.', 'en' => 'Upload a photo or PDF of any page in your handwriting.'], 'settings' => ['max_mb' => 10, 'types' => 'pdf,jpg,jpeg,png']],
            ['type' => 'random', 'points' => 1, 'body' => [], 'settings' => ['category_id' => $category->id, 'count' => 2]],
        ];
    }

    /** A plain picture for the hotspot question: three circles, the green one in the middle. */
    private function picture(): string
    {
        $png = null;
        if (function_exists('imagecreatetruecolor')) {
            $img = imagecreatetruecolor(500, 300);
            imagefill($img, 0, 0, imagecolorallocate($img, 245, 245, 245));
            foreach ([[90, [220, 60, 60]], [250, [40, 170, 90]], [410, [60, 100, 220]]] as [$x, $rgb]) {   // red, green, blue
                imagefilledellipse($img, $x, 150, 100, 100, imagecolorallocate($img, ...$rgb));
            }
            ob_start();
            imagepng($img);
            $png = ob_get_clean();
        }
        // Without GD: a 1×1 grey picture still works for trying the question.
        $png ??= base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4//8/AAX+Av4N70a4AAAAAElFTkSuQmCC');
        $name = hash('sha256', $png).'.png';
        Storage::disk('local')->put(ManageController::IMAGES.'/'.$name, $png);

        return $name;
    }
}
