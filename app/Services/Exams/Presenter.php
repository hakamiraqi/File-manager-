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
            'is_practice' => $exam->is_practice,
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
        // With versions (A/B/C), everyone on a version sees the same choice order; version A keeps
        // the written order unless choices are shuffled anyway.
        $seed = $attempt->variant ? 'v'.$exam->id.'-'.$attempt->variant : $attempt->id;
        if (($exam->shuffle_choices || $attempt->variant > 1) && in_array($q->type, [Question::SINGLE, Question::MULTIPLE], true)) {
            $choices = $choices->sortBy(fn (Choice $c) => crc32($seed.'-c-'.$c->id))->values();
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
            'body' => $q->type === Question::CALCULATED ? Calculated::render($text($q->body), Calculated::values($q, $attempt->id)) : $text($q->body),
            'html' => $q->format === 'html',
            'points' => $q->points,
            'choices' => in_array($q->type, [Question::MATCHING, Question::CLASSIFY], true) || ! in_array($q->type, Question::CHOICE_TYPES, true) ? [] : $choices
                ->map(fn (Choice $c) => ['id' => $c->id, 'body' => $text($c->body)])->all(),
        ];

        if (in_array($q->type, [Question::MATCHING, Question::CLASSIFY], true)) {
            $data['items'] = $choices->map(fn (Choice $c) => ['id' => $c->id, 'body' => $text($c->body)])->all();
            $data['targets'] = $choices->sortBy(fn (Choice $c) => crc32($attempt->id.'-m-'.$c->id))
                ->unique(fn (Choice $c) => Grader::normalize($text($c->match)))
                ->map(fn (Choice $c) => ['id' => $c->id, 'body' => $text($c->match)])->values()->all();
        }
        if ($q->type === Question::DRAG_TEXT) {
            // The text with its blanks, and every word (the right ones and the extra ones) to drag in.
            $parts = Cloze::parse($data['body']);
            $words = collect($parts)->filter(fn ($p) => isset($p['blank']))->map(fn ($p) => $p['answers'][0] ?? '')
                ->merge((array) ($q->settings['distractors'] ?? []))->filter()->unique()
                ->sortBy(fn ($w) => crc32($attempt->id.'-d-'.$w))->values()->all();
            $data['parts'] = collect($parts)->map(fn ($p) => isset($p['blank']) ? ['blank' => $p['blank'], 'kind' => 'drag'] : $p)->all();
            $data['words'] = $words;
            $data['body'] = '';
        }
        if ($q->type === Question::DRAG_IMAGE) {
            $data['image'] = self::imageUrl($q->settings['image'] ?? null);
            $data['labels'] = $choices->sortBy(fn (Choice $c) => crc32($attempt->id.'-l-'.$c->id))->map(fn (Choice $c) => ['id' => $c->id, 'body' => $text($c->body)])->values()->all();
            $data['choices'] = [];
        }
        if ($q->type === Question::AUDIO) {
            $data['max_seconds'] = (int) ($q->settings['max_seconds'] ?? 120);
        }
        if ($q->type === Question::CLOZE) {
            // The text in pieces; the answers never leave the server, a list's options are shuffled.
            $data['parts'] = collect(Cloze::parse($data['body']))->map(function ($p) use ($attempt) {
                if (! isset($p['blank'])) {
                    return $p;
                }
                $options = collect($p['options'])->sortBy(fn ($o) => crc32($attempt->id.'-z-'.$p['blank'].'-'.$o))->values()->all();

                return ['blank' => $p['blank'], 'kind' => $p['kind'], 'options' => $options];
            })->all();
            $data['body'] = '';
        }
        if ($q->type === Question::UPLOAD) {
            $data['max_mb'] = (int) ($q->settings['max_mb'] ?? 10);
            $data['types'] = $q->settings['types'] ?? '';
        }
        if ($q->type === Question::HOTSPOT) {
            $data['image'] = self::imageUrl($q->settings['image'] ?? null);
        }
        if ($q->type === Question::ESSAY) {
            $data['min_words'] = $q->settings['min_words'] ?? null;
            $data['max_words'] = $q->settings['max_words'] ?? null;
        }

        return $data;
    }

    /** The review after submitting: the answer, whether it was right and, if allowed, the correct one. */
    public function review(Question $q, Exam $exam, ?\App\Models\Exams\Answer $answer, bool $showAnswers, ?int $attemptId = null): array
    {
        $locale = app()->getLocale();
        $langs = $exam->languages ?? [];
        $text = fn ($v) => Question::pick($v, $locale, $langs);
        $attemptId ??= $answer?->exam_attempt_id;
        $values = $q->type === Question::CALCULATED && $attemptId ? Calculated::values($q, $attemptId) : null;

        $correct = null;
        if ($showAnswers) {
            $correct = match ($q->type) {
                Question::SINGLE, Question::TRUE_FALSE, Question::MULTIPLE => $q->choices->where('is_correct', true)->map(fn ($c) => $text($c->body))->values()->all(),
                Question::SHORT => array_values((array) ($q->settings['answers'] ?? [])),
                Question::NUMERIC => [($q->settings['answer'] ?? '').(filled($q->settings['tolerance'] ?? null) ? ' ± '.$q->settings['tolerance'] : '')],
                Question::MATCHING, Question::CLASSIFY => $q->choices->map(fn ($c) => $text($c->body).' ← '.$text($c->match))->all(),
                Question::ORDERING => $q->choices->sortBy('position')->map(fn ($c) => $text($c->body))->values()->all(),
                Question::CALCULATED => $values !== null && ($e = Calculated::expected($q, $values)) !== null
                    ? [Calculated::format($e).(($q->settings['tolerance'] ?? '0') !== '0' ? ' ± '.$q->settings['tolerance'] : '')]
                    : ['= '.($q->settings['formula'] ?? '')],
                Question::DRAG_IMAGE => $q->choices->map(fn ($c) => $text($c->body))->values()->all(),
                Question::CLOZE, Question::DRAG_TEXT => collect(Cloze::blanks($text($q->body)))->map(fn ($b) => ($b['blank'] + 1).': '.($b['answers'][0] ?? ''))->all(),
                default => null,
            };
        }

        return [
            'id' => $q->id,
            'type' => $q->type,
            'body' => match (true) {
                in_array($q->type, Question::BLANK_TYPES, true) => preg_replace('/\[\[.+?\]\]/u', '____', $text($q->body)),
                $values !== null => Calculated::render($text($q->body), $values),
                default => $text($q->body),
            },
            'html' => $q->format === 'html',
            'points' => $q->points,
            'earned' => $answer?->points,
            'given' => $this->describeResponse($q, $answer?->response, $text),
            'correct' => $correct,
            'feedback' => $showAnswers ? $text($q->feedback) : '',
            'comment' => $answer?->comment,
        ] + ($q->type === Question::DRAG_IMAGE ? [
            'image' => self::imageUrl($q->settings['image'] ?? null),
            'marks' => $q->choices->sortBy('position')->values()->map(fn ($c, $i) => [
                'label' => $text($c->body),
                'at' => isset($answer?->response['places'][$c->id]) ? $answer->response['places'][$c->id] : null,
                'zone' => $showAnswers ? ($q->settings['zones'][$i] ?? null) : null,
            ])->all(),
        ] : []) + ($q->type === Question::HOTSPOT ? [
            'image' => self::imageUrl($q->settings['image'] ?? null),
            'point' => isset($answer?->response['x']) ? ['x' => (float) $answer->response['x'], 'y' => (float) $answer->response['y']] : null,
            'zones' => $showAnswers ? array_values((array) ($q->settings['zones'] ?? [])) : [],
        ] : []);
    }

    /** Where the page loads a question's picture (kept privately, served to signed-in users). */
    public static function imageUrl(?string $name): ?string
    {
        return $name ? route('exams.image', $name) : null;
    }

    /** A response in words, for the review and the examiner. */
    public function describeResponse(Question $q, ?array $response, ?callable $text = null): ?string
    {
        $text ??= fn ($v) => Question::pick($v, app()->getLocale(), $q->exam?->languages ?? []);
        if (! $response) {
            return null;
        }
        $byId = $q->choices->keyBy('id');

        return match ($q->type) {
            Question::SINGLE, Question::TRUE_FALSE => isset($byId[(int) ($response['choice'] ?? 0)]) ? $text($byId[(int) $response['choice']]->body) : null,
            Question::MULTIPLE => collect($response['choices'] ?? [])->map(fn ($id) => isset($byId[(int) $id]) ? $text($byId[(int) $id]->body) : null)->filter()->implode('، ') ?: null,
            Question::SHORT, Question::ESSAY => $response['text'] ?? null,
            Question::NUMERIC, Question::CALCULATED => isset($response['value']) ? (string) $response['value'] : null,
            Question::MATCHING, Question::CLASSIFY => collect($response['pairs'] ?? [])->map(fn ($target, $item) => isset($byId[(int) $item], $byId[(int) $target])
                ? $text($byId[(int) $item]->body).' ← '.$text($byId[(int) $target]->match) : null)->filter()->implode(' · ') ?: null,
            Question::UPLOAD, Question::AUDIO, Question::DRAWING => $response['name'] ?? null,
            Question::DRAG_IMAGE => isset($response['places']) ? count((array) $response['places']).' / '.$q->choices->count() : null,
            Question::CLOZE, Question::DRAG_TEXT => collect($response['blanks'] ?? [])->filter(fn ($v) => trim((string) $v) !== '')->map(fn ($v, $i) => ((int) $i + 1).': '.$v)->implode(' · ') ?: null,
            Question::HOTSPOT => isset($response['x'], $response['y']) ? sprintf('(%.0f%%، %.0f%%)', $response['x'], $response['y']) : null,
            Question::ORDERING => collect($response['order'] ?? [])->map(fn ($id) => isset($byId[(int) $id]) ? $text($byId[(int) $id]->body) : null)->filter()->implode(' ← ') ?: null,
            default => null,
        };
    }

    /** One question on paper, with its answer when printing the key. */
    public function forPrint(Question $q, Exam $exam, bool $key): array
    {
        $locale = app()->getLocale();
        $langs = $exam->languages ?? [];
        $text = fn ($v) => Question::pick($v, $locale, $langs);
        $data = [
            'id' => $q->id,
            'type' => $q->type,
            'body' => in_array($q->type, Question::BLANK_TYPES, true) ? preg_replace('/\[\[(\*?)(.+?)\]\]/u', '________', $text($q->body)) : $text($q->body),
            'html' => $q->format === 'html',
            'points' => $q->type === Question::RANDOM ? $q->slotPoints() : $q->points,
            'choices' => in_array($q->type, [Question::SINGLE, Question::MULTIPLE, Question::TRUE_FALSE, Question::ORDERING, Question::DRAG_IMAGE], true)
                ? $q->choices->map(fn (Choice $c) => ['body' => $text($c->body), 'is_correct' => $key && $c->is_correct])->values()->all() : [],
        ];
        if ($q->type === Question::ORDERING) {
            // On paper too the right order would give the answer away.
            $data['choices'] = collect($data['choices'])->sortBy(fn ($c) => crc32($q->id.$c['body']))->values()->all();
        }
        if (in_array($q->type, [Question::MATCHING, Question::CLASSIFY], true)) {
            $data['items'] = $q->choices->map(fn (Choice $c) => $text($c->body))->values()->all();
            $data['targets'] = $q->choices->map(fn (Choice $c) => $text($c->match))->unique(fn ($m) => Grader::normalize($m))
                ->sortBy(fn ($m) => crc32($q->id.$m))->values()->all();
        }
        if (in_array($q->type, [Question::HOTSPOT, Question::DRAG_IMAGE], true)) {
            $data['image'] = self::imageUrl($q->settings['image'] ?? null);
            $data['zones'] = $key ? array_values((array) ($q->settings['zones'] ?? [])) : [];
        }
        if ($q->type === Question::RANDOM) {
            $data['count'] = (int) ($q->settings['count'] ?? 0);
            $data['category'] = \App\Models\Exams\BankCategory::whereKey($q->settings['category_id'] ?? 0)->value('name');
        }
        if ($key && ! in_array($q->type, [Question::SINGLE, Question::MULTIPLE, Question::TRUE_FALSE], true)) {
            $data['answer'] = $this->review($q, $exam, null, true)['correct'];
        }

        return $data;
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
            'is_practice' => $exam->is_practice,
            'seb_required' => $exam->seb_required,
            'seb_keys' => $exam->seb_keys,
            'double_marking' => $exam->double_marking,
            'access_code' => $exam->access_code,
            'resume_code_required' => $exam->resume_code_required,
            'navigation' => $exam->navigation ?? 'free',
            'results_at' => $exam->results_at?->format('Y-m-d\TH:i'),
            'anonymous_marking' => $exam->anonymous_marking,
            'appeals_open' => $exam->appeals_open,
            'calculator' => $exam->calculator ?? 'none',
            'bind_computers' => $exam->bind_computers,
            'variants' => $exam->variants ?? 1,
            'protect_content' => $exam->protect_content ?? true,
            'allowed_ips' => $exam->allowed_ips,
            'group_ids' => $exam->groups()->pluck('exam_groups.id')->all(),
            'marking_tolerance' => $exam->marking_tolerance,
            'questions' => $exam->questions()->with('choices')->get()->map(fn (Question $q) => $this->questionForEditor($q))->all(),
        ];
    }

    public function questionForEditor(Question $q): array
    {
        return [
            'id' => $q->id,
            'type' => $q->type,
            'format' => $q->format ?? 'text',
            'tags' => $q->tags ?? [],
            'difficulty' => $q->difficulty,
            'body' => $q->body ?: (object) [],
            'feedback' => $q->feedback ?: (object) [],
            'points' => $q->points,
            'settings' => ($q->settings ?: []) + (in_array($q->type, [Question::HOTSPOT, Question::DRAG_IMAGE], true) ? ['image_url' => self::imageUrl($q->settings['image'] ?? null)] : []) ?: (object) [],
            'choices' => $q->choices->map(fn (Choice $c) => [
                'id' => $c->id, 'body' => $c->body ?: (object) [], 'match' => $c->match ?: (object) [], 'is_correct' => $c->is_correct,
            ])->all(),
        ];
    }
}
