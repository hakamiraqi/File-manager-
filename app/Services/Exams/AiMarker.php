<?php

namespace App\Services\Exams;

use App\Models\Exams\Answer;
use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A suggested mark for a written answer, from Claude (Anthropic API), with the reason and, when
 * the question has a rubric, a mark per criterion. Only on the hosted site (it needs the
 * internet); only the question, its rubric and the answer are sent, never who wrote it.
 * The teacher decides: nothing is saved until they do.
 */
class AiMarker
{
    public static function enabled(): bool
    {
        return ! config('offline.edition') && filled(Setting::get('ai_api_key'));
    }

    public function suggest(Answer $answer, float $max): array
    {
        if (! self::enabled()) {
            throw new RuntimeException('اقتراح الدرجات غير مفعّل.');
        }
        $q = $answer->question;
        $rubric = $q->settings['rubric'] ?? [];
        $text = trim((string) ($answer->response['text'] ?? ''));
        if ($text === '') {
            throw new RuntimeException('لا نص في الإجابة لاقتراح درجة له.');
        }
        $prompt = "أنت مساعد تصحيح لأستاذ جامعي. اقترح درجة لإجابة طالب، والأستاذ هو من يقرّر.\n\n"
            ."السؤال:\n".$q->plainBody()."\n\n"
            .($q->feedback ? "ملاحظات الأستاذ أو الإجابة النموذجية:\n".HtmlCleaner::text((string) collect($q->feedback)->first())."\n\n" : '')
            ."الدرجة القصوى: {$max}\n"
            .($rubric ? "معايير التصحيح (المعيار: أقصى درجة):\n".collect($rubric)->map(fn ($c, $k) => ($k + 1).". {$c['name']}: {$c['max']}")->implode("\n")."\n" : '')
            ."\nإجابة الطالب:\n<answer>\n".mb_substr($text, 0, 12000)."\n</answer>\n\n"
            .'أجب بـ JSON فقط بالشكل: {"points": رقم, '.($rubric ? '"rubric": [درجة لكل معيار بالترتيب], ' : '').'"reason": "سبب مختصر بلغة الإجابة"}';

        $response = Http::timeout(60)->withHeaders([
            'x-api-key' => Crypt::decryptString((string) Setting::get('ai_api_key')),
            'anthropic-version' => '2023-06-01',
        ])->post('https://api.anthropic.com/v1/messages', [
            'model' => Setting::get('ai_model') ?: 'claude-sonnet-5-5',
            'max_tokens' => 800,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]);
        if ($response->failed()) {
            throw new RuntimeException('تعذّر الاتصال بخدمة الذكاء الاصطناعي ('.$response->status().').');
        }
        $first = collect($response->json('content') ?? [])->firstWhere('type', 'text');
        $out = (string) ($first['text'] ?? '');
        if (! preg_match('/\{.*\}/s', $out, $m) || ! is_array($json = json_decode($m[0], true))) {
            throw new RuntimeException('لم أفهم ردّ الخدمة.');
        }
        $points = round(max(0, min($max, (float) ($json['points'] ?? 0))), 2);
        $byCriterion = $rubric ? collect($rubric)->map(fn ($c, $k) => round(max(0, min($c['max'], (float) ($json['rubric'][$k] ?? 0))), 2))->all() : null;

        return ['points' => $byCriterion ? round(array_sum($byCriterion) * $max / max(0.001, array_sum(array_column($rubric, 'max'))), 2) : $points,
            'rubric' => $byCriterion, 'reason' => mb_substr((string) ($json['reason'] ?? ''), 0, 2000)];
    }
}
