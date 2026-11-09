<?php

namespace App\Services\Exams;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Draft questions from a lecture (a PDF, a Word file or pasted text), by Claude (hosted site,
 * with the AI key). The teacher reviews each and keeps only what is right; nothing goes into
 * the bank before that.
 */
class AiQuestions
{
    public const TYPES = ['single', 'true_false', 'short'];

    /** @return array<int, array{type: string, body: string, choices: array, answer: ?string, difficulty: int}> */
    public function generate(?string $pdfPath, string $text, int $count, array $types, string $lang): array
    {
        if (! AiMarker::enabled()) {
            throw new RuntimeException('توليد الأسئلة غير مفعّل.');
        }
        $types = array_values(array_intersect($types, self::TYPES)) ?: ['single'];
        $instructions = "أنت تساعد أستاذاً جامعياً في إعداد بنك أسئلة من محاضرة. اكتب {$count} سؤالاً من المحتوى المرفق فقط، بلغة "
            .($lang === 'en' ? 'الإنكليزية' : 'العربية').'، متنوعة الصعوبة (1 سهل، 2 متوسط، 3 صعب)، وأنواعها من: '.implode('، ', $types).".\n"
            ."- single: اختيار من متعدد بأربعة خيارات، واحد صحيح، والخيارات الخاطئة معقولة.\n- true_false: عبارة صحيحة أو خاطئة.\n- short: جواب قصير بكلمة أو عبارة.\n"
            .'أجب بـ JSON فقط: {"questions": [{"type": "...", "body": "...", "choices": [{"text": "...", "correct": true|false}], "answer": "للأسئلة القصيرة وصح/خطأ: الجواب أو true/false", "difficulty": 1|2|3, "tag": "عنوان الفصل أو الفكرة"}]}';
        $content = [];
        if ($pdfPath) {
            $content[] = ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode((string) file_get_contents($pdfPath))]];
        }
        if (trim($text) !== '') {
            $content[] = ['type' => 'text', 'text' => "<lecture>\n".mb_substr($text, 0, 150000)."\n</lecture>"];
        }
        $content[] = ['type' => 'text', 'text' => $instructions];

        $response = Http::timeout(180)->withHeaders([
            'x-api-key' => Crypt::decryptString((string) Setting::get('ai_api_key')),
            'anthropic-version' => '2023-06-01',
        ])->post('https://api.anthropic.com/v1/messages', [
            'model' => Setting::get('ai_model') ?: 'claude-sonnet-5-5',
            'max_tokens' => 8000,
            'messages' => [['role' => 'user', 'content' => $content]],
        ]);
        if ($response->failed()) {
            throw new RuntimeException('تعذّر الاتصال بخدمة الذكاء الاصطناعي ('.$response->status().').');
        }
        $out = (string) (collect($response->json('content') ?? [])->firstWhere('type', 'text')['text'] ?? '');
        if (! preg_match('/\{.*\}/s', $out, $m) || ! is_array($json = json_decode($m[0], true)) || ! is_array($json['questions'] ?? null)) {
            throw new RuntimeException('لم أفهم ردّ الخدمة.');
        }

        return collect($json['questions'])->filter(fn ($q) => in_array($q['type'] ?? null, $types, true) && filled($q['body'] ?? null))
            ->map(fn ($q) => [
                'type' => $q['type'], 'body' => mb_substr((string) $q['body'], 0, 3000),
                'choices' => $q['type'] === 'single' ? collect($q['choices'] ?? [])->take(6)->map(fn ($c) => ['text' => mb_substr((string) ($c['text'] ?? ''), 0, 500), 'correct' => (bool) ($c['correct'] ?? false)])->values()->all() : [],
                'answer' => isset($q['answer']) ? mb_substr(is_bool($q['answer']) ? ($q['answer'] ? 'true' : 'false') : (string) $q['answer'], 0, 300) : null,
                'difficulty' => max(1, min(3, (int) ($q['difficulty'] ?? 2))),
                'tag' => mb_substr((string) ($q['tag'] ?? ''), 0, 100),
            ])->filter(fn ($q) => $q['type'] !== 'single' || (count($q['choices']) >= 2 && collect($q['choices'])->where('correct', true)->count() === 1))
            ->values()->take($count)->all();
    }
}
