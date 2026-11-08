<?php

namespace App\Services\Exams;

use App\Models\Exams\Question;

/**
 * Calculated questions: the text has variables in braces, «سرعة {v} م/ث لمدة {t} ث»; each
 * attempt gets its own values, drawn from the ranges the examiner set and always the same for
 * that attempt (so a reload shows the same numbers), and the answer is the formula on them.
 * settings: {vars: [{name, min, max, decimals}], formula, tolerance ("0.1" or "2%"), decimals}
 */
class Calculated
{
    /** @return array<string, float> */
    public static function values(Question $q, int $attemptId): array
    {
        $values = [];
        foreach ((array) ($q->settings['vars'] ?? []) as $v) {
            $min = (float) $v['min'];
            $max = (float) $v['max'];
            $r = crc32("{$attemptId}-{$q->id}-{$v['name']}") / 4294967295;
            $values[$v['name']] = round($min + $r * ($max - $min), (int) ($v['decimals'] ?? 0));
        }

        return $values;
    }

    public static function expected(Question $q, array $values): ?float
    {
        try {
            $value = Formula::evaluate((string) ($q->settings['formula'] ?? ''), $values);
        } catch (\InvalidArgumentException) {
            return null;
        }
        $decimals = $q->settings['decimals'] ?? null;

        return $decimals === null || $decimals === '' ? $value : round($value, (int) $decimals);
    }

    /** The text with the attempt's numbers in place of {name}. */
    public static function render(string $text, array $values): string
    {
        return preg_replace_callback('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', function ($m) use ($values) {
            $key = collect(array_keys($values))->first(fn ($k) => strcasecmp($k, $m[1]) === 0);

            return $key === null ? $m[0] : self::format($values[$key]);
        }, $text);
    }

    public static function format(float $n): string
    {
        $s = rtrim(rtrim(number_format($n, 6, '.', ''), '0'), '.');

        return $s === '-0' ? '0' : $s;
    }

    public static function isRight(Question $q, float $given, array $values): bool
    {
        $expected = self::expected($q, $values);
        if ($expected === null) {
            return false;
        }
        $tolerance = trim((string) ($q->settings['tolerance'] ?? '0'));
        $allowed = str_ends_with($tolerance, '%')
            ? abs($expected) * abs((float) Grader::toNumber(rtrim($tolerance, '%'))) / 100
            : abs((float) Grader::toNumber($tolerance));

        return abs($given - $expected) <= $allowed + 1e-9;
    }
}
