<?php

namespace App\Models\Exams;

use Illuminate\Database\Eloquent\Model;

/** One change of one answer, as it reached the server: what, when and from where. */
class AnswerLog extends Model
{
    public const UPDATED_AT = null;

    /** Kept per attempt; beyond this a runaway page stops filling the table. */
    public const LIMIT = 3000;

    protected $table = 'exam_answer_logs';

    protected $fillable = ['exam_attempt_id', 'exam_question_id', 'response', 'source', 'ip'];

    protected function casts(): array
    {
        return ['response' => 'array'];
    }

    /** Records a change; long texts are kept to their first part (the full answer is in the answer itself). */
    public static function record(Attempt $attempt, int $questionId, ?array $response, string $source, ?string $ip): void
    {
        if (static::where('exam_attempt_id', $attempt->id)->count() >= self::LIMIT) {
            return;
        }
        if (isset($response['text']) && is_string($response['text']) && mb_strlen($response['text']) > 2000) {
            $response['text'] = mb_substr($response['text'], 0, 2000).'…';
        }
        static::create(['exam_attempt_id' => $attempt->id, 'exam_question_id' => $questionId, 'response' => $response, 'source' => $source, 'ip' => $ip]);
    }
}
