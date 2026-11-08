<?php

namespace App\Services\Exams;

use App\Models\Exams\Question;

/**
 * «املأ الفراغ»: the question text carries its blanks.
 *   [[بغداد|بغدادُ]]          a blank the student types; any of the answers is accepted
 *   [[*بغداد|البصرة|الموصل]]   a list to pick from; the starred one is right (shown shuffled)
 * Each language of the question has the same blanks in the same order.
 */
class Cloze
{
    private const PATTERN = '/\[\[(.+?)\]\]/u';

    /** The text split into pieces: ['text' => '...'] or ['blank' => n, 'kind' => 'text'|'select', 'answers' => [], 'options' => []]. */
    public static function parse(string $text): array
    {
        $parts = [];
        $offset = 0;
        $n = 0;
        preg_match_all(self::PATTERN, $text, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as $i => [$whole, $pos]) {
            if ($pos > $offset) {
                $parts[] = ['text' => substr($text, $offset, $pos - $offset)];
            }
            $inner = $matches[1][$i][0];
            $options = array_values(array_filter(array_map('trim', explode('|', $inner)), fn ($o) => $o !== '' && $o !== '*'));
            $select = str_starts_with(ltrim($inner), '*');
            if ($select) {
                $options[0] = ltrim($options[0] ?? '', '*');
                $options[0] = trim($options[0]);
            }
            $parts[] = [
                'blank' => $n++,
                'kind' => $select ? 'select' : 'text',
                'answers' => $select ? [$options[0] ?? ''] : $options,
                'options' => $select ? $options : [],
            ];
            $offset = $pos + strlen($whole);
        }
        if ($offset < strlen($text)) {
            $parts[] = ['text' => substr($text, $offset)];
        }

        return $parts;
    }

    public static function blanks(string $text): array
    {
        return array_values(array_filter(self::parse($text), fn ($p) => isset($p['blank'])));
    }

    /** Accepted answers per blank, from every language of the question. */
    public static function acceptedByBlank(Question $q): array
    {
        $accepted = [];
        foreach ((array) $q->body as $text) {
            foreach (self::blanks((string) $text) as $b) {
                $accepted[$b['blank']] = array_merge($accepted[$b['blank']] ?? [], $b['answers']);
            }
        }

        return $accepted;
    }

    /** How many blanks the question has (by its first language). */
    public static function count(Question $q, array $languages = []): int
    {
        $first = Question::pick($q->body, $languages[0] ?? null, $languages);

        return count(self::blanks($first));
    }
}
