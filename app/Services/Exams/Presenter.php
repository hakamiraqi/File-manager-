<?php

namespace App\Services\Exams;

use App\Models\Exams\Attempt;
use App\Models\Exams\Choice;
use App\Models\Exams\Exam;
use App\Models\Exams\Question;
use App\Models\User;

/** Shapes exams for the pages: what a student may see (never the answers) and what an examiner edits. */
class Presenter
{
    public function card(Exam $exam, User $user): array
    {
        $attempts = $exam->attempts->where('user_id', $user->id);
        $open = $attempts->first(fn (Attempt $a) => ! $a->isSubmitted());

        return [
            'id' => $exam->id,
            'title' => $exam->text('title'),
            'subject' => $exam->subject,
            'stage' => $exam->stage,
            'department' => $exam->department,
            'duration' => $exam->minutesFor($user),
            'opens_at' => $exam->opens_at?->toIso8601String(),
            'closes_at' => $exam->closesAtFor($user)?->toIso8601String(),
            'window' => $exam->windowFor($user),
            'questions' => $exam->questions_count ?? $exam->questions()->count(),
            'attempts_used' => $attempts->count(),
            'max_attempts' => $exam->maxAttemptsFor($user),
            'in_progress' => $open?->id,
            'score' => $exam->resultsVisibleTo($user) ? $exam->finalScoreFor($user) : null,
            'max_score' => $attempts->max('max_score'),
            'dir' => $this->dir($exam),
        ];
    }

    public function dir(Exam $exam): string
    {
        if (in_array($exam->direction, ['rtl', 'ltr'], true)) {
            return $exam->direction;
        }

        return in_array($exam->languages[0] ?? 'ar', ['ar', 'ku'], true) ? 'rtl' : 'ltr';
    }

    /** One question as the student sees it while answering. */
    public function forStudent(Question $q, Exam $exam, Attempt $attempt): array
    {
        $locale = app()->getLocale();
        $langs = $exam->languages ?? [];
        $text = fn ($v) => Question::pick($v, $locale, $langs);
        $choices = $q->choices->values();
        if ($exam->shuffle_choices && in_array($q->type, [Question::SINGLE, Question::MULTIPLE], true)) {
            $choices = $choices->sortBy(fn (Choice $c) => crc32($attempt->id.'-c-'.$c->id))->values();
        }
        if ($q->type === Question::ORDERING) {
            // Never in the right order: a stable shuffle that is not the identity.
            $shuffled = $choices->sortBy(fn (Choice $c) => crc32($attempt->id.'-o-'.$c->id))->values();
            if ($shuffled->pluck('id')->all() === $choices->pluck('id')->all() && $choices->count() > 1) {
                $shuffled = $shuffled->reverse()->values();
            }
            $choices = $shuffled;
        }

        $data = [
            'id' => $q->id,
            'type' => $q->type,
            'body' => $text($q->body),
            'points' => $q->points,
            'choices' => $q->type === Question::MATCHING || ! in_array($q->type, Question::CHOICE_TYPES, true) ? [] : $choices
                ->map(fn (Choice $c) => ['id' => $c->id, 'body' => $text($c->body)])->all(),
        ];

        if ($q->type === Question::MATCHING) {
            $data['items'] = $choices->map(fn (Choice $c) => ['id' => $c->id, 'body' => $text($c->body)])->all();
            $data['targets'] = $choices->sortBy(fn (Choice $c) => crc32($attempt->id.'-m-'.$c->id))
                ->unique(fn (Choice $c) => Grader::normalize($text($c->match)))
                ->map(fn (Choice $c) => ['id' => $c->id, 'body' => $text($c->match)])->values()->all();
        }
        if ($q->type === Question::ESSAY) {
            $data['min_words'] = $q->settings['min_words'] ?? null;
            $data['max_words'] = $q->settings['max_words'] ?? null;
        }

        return $data;
    }

    /** The review after submitting: the answer, whether it was right and, if allowed, the correct one. */
    public function review(Question $q, Exam $exam, ?\App\Models\Exams\Answer $answer, bool $showAnswers): array
    {
        $locale = app()->getLocale();
        $langs = $exam->languages ?? [];
        $text = fn ($v) => Question::pick($v, $locale, $langs);

        $correct = null;
        if ($showAnswers) {
            $correct = match ($q->type) {
                Question::SINGLE, Question::TRUE_FALSE, Question::MULTIPLE => $q->choices->where('is_correct', true)->map(fn ($c) => $text($c->body))->values()->all(),
                Question::SHORT => array_values((array) ($q->settings['answers'] ?? [])),
                Question::NUMERIC => [($q->settings['answer'] ?? '').(filled($q->settings['tolerance'] ?? null) ? ' ± '.$q->settings['tolerance'] : '')],
                Question::MATCHING => $q->choices->map(fn ($c) => $text($c->body).' ← '.$text($c->match))->all(),
                Question::ORDERING => $q->choices->sortBy('position')->map(fn ($c) => $text($c->body))->values()->all(),
                default => null,
            };
        }

        return [
            'id' => $q->id,
            'type' => $q->type,
            'body' => $text($q->body),
            'points' => $q->points,
            'earned' => $answer?->points,
            'given' => $this->describeResponse($q, $answer?->response, $text),
            'correct' => $correct,
            'feedback' => $showAnswers ? $text($q->feedback) : '',
            'comment' => $answer?->comment,
        ];
    }

    /** A response in words, for the review and the examiner. */
    public function describeResponse(Question $q, ?array $response, ?callable $text = null): ?string
    {
        $text ??= fn ($v) => Question::pick($v, app()->getLocale(), $q->exam->languages ?? []);
        if (! $response) {
            return null;
        }
        $byId = $q->choices->keyBy('id');

        return match ($q->type) {
            Question::SINGLE, Question::TRUE_FALSE => isset($byId[(int) ($response['choice'] ?? 0)]) ? $text($byId[(int) $response['choice']]->body) : null,
            Question::MULTIPLE => collect($response['choices'] ?? [])->map(fn ($id) => isset($byId[(int) $id]) ? $text($byId[(int) $id]->body) : null)->filter()->implode('، ') ?: null,
            Question::SHORT, Question::ESSAY => $response['text'] ?? null,
            Question::NUMERIC => isset($response['value']) ? (string) $response['value'] : null,
            Question::MATCHING => collect($response['pairs'] ?? [])->map(fn ($target, $item) => isset($byId[(int) $item], $byId[(int) $target])
                ? $text($byId[(int) $item]->body).' ← '.$text($byId[(int) $target]->match) : null)->filter()->implode(' · ') ?: null,
            Question::ORDERING => collect($response['order'] ?? [])->map(fn ($id) => isset($byId[(int) $id]) ? $text($byId[(int) $id]->body) : null)->filter()->implode(' ← ') ?: null,
            default => null,
        };
    }

    /** The whole exam for the editor, every language kept. */
    public function forEditor(Exam $exam): array
    {
        return [
            'id' => $exam->id,
            'title' => $exam->title ?: (object) [],
            'description' => $exam->description ?: (object) [],
            'subject' => $exam->subject,
            'stage' => $exam->stage,
            'department' => $exam->department,
            'languages' => $exam->languages,
            'direction' => $exam->direction,
            'duration_minutes' => $exam->duration_minutes,
            'opens_at' => $exam->opens_at?->format('Y-m-d\TH:i'),
            'closes_at' => $exam->closes_at?->format('Y-m-d\TH:i'),
            'max_attempts' => $exam->max_attempts,
            'grading_method' => $exam->grading_method,
            'pass_mark' => $exam->pass_mark,
            'password' => $exam->password,
            'shuffle_questions' => $exam->shuffle_questions,
            'shuffle_choices' => $exam->shuffle_choices,
            'show_results' => $exam->show_results,
            'show_answers' => $exam->show_answers,
            'is_published' => $exam->is_published,
            'questions' => $exam->questions()->with('choices')->get()->map(fn (Question $q) => [
                'id' => $q->id,
                'type' => $q->type,
                'body' => $q->body ?: (object) [],
                'feedback' => $q->feedback ?: (object) [],
                'points' => $q->points,
                'settings' => $q->settings ?: (object) [],
                'choices' => $q->choices->map(fn (Choice $c) => [
                    'id' => $c->id, 'body' => $c->body ?: (object) [], 'match' => $c->match ?: (object) [], 'is_correct' => $c->is_correct,
                ])->all(),
            ])->all(),
        ];
    }
}
