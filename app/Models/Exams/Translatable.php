<?php

namespace App\Models\Exams;

/**
 * Texts kept in several languages as {"ar": "...", "en": "..."}.
 * A text is shown in the reader's language, else the exam's first language, else any.
 */
trait Translatable
{
    public static function pick(mixed $value, ?string $locale = null, array $fallbacks = []): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (! is_array($value) || $value === []) {
            return '';
        }
        foreach (array_filter([$locale, ...$fallbacks]) as $lang) {
            if (filled($value[$lang] ?? null)) {
                return (string) $value[$lang];
            }
        }
        foreach ($value as $text) {
            if (filled($text)) {
                return (string) $text;
            }
        }

        return '';
    }

    /** Keeps only the exam's languages and drops empty strings. */
    public static function cleanTexts(mixed $value, array $languages): array
    {
        if (is_string($value)) {
            $value = [$languages[0] ?? 'ar' => $value];
        }

        return collect(is_array($value) ? $value : [])
            ->only($languages)
            ->map(fn ($t) => is_string($t) ? trim($t) : '')
            ->filter(fn ($t) => $t !== '')
            ->all();
    }
}
