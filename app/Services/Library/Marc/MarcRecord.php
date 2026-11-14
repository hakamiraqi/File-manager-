<?php

namespace App\Services\Library\Marc;

/**
 * One MARC record (MARC21 or UNIMARC), as the library world exchanges them (2026-11-14).
 * Control fields (tags 001–009) hold plain data; data fields hold two indicators and
 * subfields, kept in order because order carries meaning (245 $a $b $c).
 *
 * @phpstan-type Field array{tag: string, data?: string, ind1?: string, ind2?: string, subfields?: list<array{0: string, 1: string}>}
 */
class MarcRecord
{
    /** @param list<array> $fields */
    public function __construct(public string $leader = '00000nam a2200000 a 4500', public array $fields = []) {}

    public static function isControl(string $tag): bool
    {
        return $tag < '010' && ctype_digit($tag);
    }

    /** @return list<array> */
    public function all(string $tag): array
    {
        return array_values(array_filter($this->fields, fn ($f) => $f['tag'] === $tag));
    }

    public function has(string $tag): bool
    {
        foreach ($this->fields as $f) {
            if ($f['tag'] === $tag) {
                return true;
            }
        }

        return false;
    }

    public function control(string $tag): ?string
    {
        foreach ($this->fields as $f) {
            if ($f['tag'] === $tag && isset($f['data'])) {
                return $f['data'];
            }
        }

        return null;
    }

    /** The first non-empty value of a subfield among the fields with that tag. */
    public function sub(string $tag, string $code): ?string
    {
        foreach ($this->all($tag) as $f) {
            foreach ($f['subfields'] ?? [] as [$c, $v]) {
                if ($c === $code && trim($v) !== '') {
                    return trim($v);
                }
            }
        }

        return null;
    }

    /** Every value of the given subfields across all fields with that tag. @return list<string> */
    public function subs(string $tag, string $codes = 'a'): array
    {
        $out = [];
        foreach ($this->all($tag) as $f) {
            foreach ($f['subfields'] ?? [] as [$c, $v]) {
                if (str_contains($codes, $c) && trim($v) !== '') {
                    $out[] = trim($v);
                }
            }
        }

        return $out;
    }

    /** The values of one field's subfields joined in order (e.g. 245 $a $b). */
    public static function join(array $field, string $codes, string $glue = ' '): string
    {
        $parts = [];
        foreach ($field['subfields'] ?? [] as [$c, $v]) {
            if (str_contains($codes, $c) && trim($v) !== '') {
                $parts[] = trim($v);
            }
        }

        return trim(implode($glue, $parts));
    }

    public function remove(string ...$tags): void
    {
        $this->fields = array_values(array_filter($this->fields, fn ($f) => ! in_array($f['tag'], $tags, true)));
    }

    public function addControl(string $tag, string $data): void
    {
        $this->fields[] = ['tag' => $tag, 'data' => $data];
    }

    /** @param list<array{0: string, 1: string}> $subfields empty values are left out */
    public function addField(string $tag, string $ind1, string $ind2, array $subfields): void
    {
        $subfields = array_values(array_filter($subfields, fn ($s) => trim((string) $s[1]) !== ''));
        if ($subfields) {
            $this->fields[] = ['tag' => $tag, 'ind1' => $ind1, 'ind2' => $ind2, 'subfields' => array_map(fn ($s) => [$s[0], trim((string) $s[1])], $subfields)];
        }
    }

    /** Fields in tag order (control fields first), as catalogues expect. */
    public function sort(): void
    {
        $i = 0;
        $keyed = array_map(function ($f) use (&$i) {
            return [$f['tag'], $i++, $f];
        }, $this->fields);
        usort($keyed, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $this->fields = array_column($keyed, 2);
    }

    /** UNIMARC keeps the title in 200, MARC21 in 245. */
    public function isUnimarc(): bool
    {
        return ! $this->has('245') && $this->has('200');
    }
}
