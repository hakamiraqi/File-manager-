<?php

namespace App\Services\Exams;

use InvalidArgumentException;

/**
 * A small, safe calculator for calculated questions: numbers, variables, + − × ÷ ^, brackets and
 * the usual functions. Nothing is passed to eval(); an unknown name or a bad formula throws.
 *   a * b + sqrt(c) / 2      round(v * t, 2)      2 * pi * r ^ 2      ln(x) + log(y)
 */
class Formula
{
    private const FUNCTIONS = ['sqrt', 'abs', 'sin', 'cos', 'tan', 'asin', 'acos', 'atan', 'log', 'ln', 'exp', 'round', 'floor', 'ceil', 'min', 'max', 'pow'];

    private array $tokens = [];

    private int $pos = 0;

    private array $vars = [];

    public static function evaluate(string $formula, array $vars = []): float
    {
        return (new self)->run($formula, $vars);
    }

    /** The variable names a formula uses. */
    public static function variables(string $formula): array
    {
        preg_match_all('/[A-Za-z_][A-Za-z0-9_]*/', $formula, $m);

        return array_values(array_unique(array_diff(array_map('strtolower', $m[0]), [...self::FUNCTIONS, 'pi', 'e'])));
    }

    private function run(string $formula, array $vars): float
    {
        $this->vars = array_change_key_case($vars, CASE_LOWER);
        $formula = strtr($formula, ['×' => '*', '÷' => '/', '−' => '-', '٫' => '.', '،' => ',']);
        if (! preg_match_all('/\s*(\d+(?:\.\d+)?(?:[eE][-+]?\d+)?|\.\d+|[A-Za-z_][A-Za-z0-9_]*|\*\*|[-+*\/^(),])\s*/A', $formula, $m) || implode('', $m[0]) !== $formula) {
            throw new InvalidArgumentException('رموز غير مفهومة في المعادلة.');
        }
        $this->tokens = array_map(fn ($t) => $t === '**' ? '^' : $t, $m[1]);
        $this->pos = 0;
        $value = $this->expression();
        if ($this->pos !== count($this->tokens)) {
            throw new InvalidArgumentException('المعادلة غير مكتملة.');
        }
        if (! is_finite($value)) {
            throw new InvalidArgumentException('ناتج المعادلة غير محدد (قسمة على صفر؟).');
        }

        return $value;
    }

    private function peek(): ?string
    {
        return $this->tokens[$this->pos] ?? null;
    }

    private function take(?string $expected = null): string
    {
        $t = $this->tokens[$this->pos] ?? null;
        if ($t === null || ($expected !== null && $t !== $expected)) {
            throw new InvalidArgumentException($expected ? "ينقص «{$expected}» في المعادلة." : 'المعادلة غير مكتملة.');
        }
        $this->pos++;

        return $t;
    }

    private function expression(): float
    {
        $v = $this->term();
        while (in_array($this->peek(), ['+', '-'], true)) {
            $v = $this->take() === '+' ? $v + $this->term() : $v - $this->term();
        }

        return $v;
    }

    private function term(): float
    {
        $v = $this->unary();
        while (in_array($this->peek(), ['*', '/'], true)) {
            if ($this->take() === '*') {
                $v *= $this->unary();
            } else {
                $d = $this->unary();
                if ($d == 0.0) {
                    throw new InvalidArgumentException('قسمة على صفر في المعادلة.');
                }
                $v /= $d;
            }
        }

        return $v;
    }

    /** A sign before a power applies to the power: -2^2 = -4. */
    private function unary(): float
    {
        if ($this->peek() === '-') {
            $this->take();

            return -$this->unary();
        }
        if ($this->peek() === '+') {
            $this->take();

            return $this->unary();
        }

        return $this->power();
    }

    private function power(): float
    {
        $base = $this->primary();
        if ($this->peek() === '^') {
            $this->take();

            return $base ** $this->unary();   // right-associative: 2^3^2 = 2^9, and 2^-1 works
        }

        return $base;
    }

    private function primary(): float
    {
        $t = $this->take();
        if (is_numeric($t)) {
            return (float) $t;
        }
        if ($t === '(') {
            $v = $this->expression();
            $this->take(')');

            return $v;
        }
        $name = strtolower($t);
        if (in_array($name, self::FUNCTIONS, true) && $this->peek() === '(') {
            $this->take('(');
            $args = [$this->expression()];
            while ($this->peek() === ',') {
                $this->take();
                $args[] = $this->expression();
            }
            $this->take(')');

            return $this->call($name, $args);
        }
        if ($name === 'pi') {
            return M_PI;
        }
        if ($name === 'e') {
            return M_E;
        }
        if (array_key_exists($name, $this->vars)) {
            return (float) $this->vars[$name];
        }
        throw new InvalidArgumentException("اسم غير معروف في المعادلة: {$t}");
    }

    private function call(string $f, array $a): float
    {
        $one = fn () => count($a) === 1 ? $a[0] : throw new InvalidArgumentException("{$f}() تأخذ قيمة واحدة.");

        return match ($f) {
            'sqrt' => $one() < 0 ? throw new InvalidArgumentException('جذر عدد سالب.') : sqrt($a[0]),
            'abs' => abs($one()),
            'sin' => sin($one()), 'cos' => cos($one()), 'tan' => tan($one()),
            'asin' => asin($one()), 'acos' => acos($one()), 'atan' => atan($one()),
            'log' => $one() <= 0 ? throw new InvalidArgumentException('لوغاريتم عدد غير موجب.') : log10($a[0]),
            'ln' => $one() <= 0 ? throw new InvalidArgumentException('لوغاريتم عدد غير موجب.') : log($a[0]),
            'exp' => exp($one()),
            'floor' => floor($one()), 'ceil' => ceil($one()),
            'round' => round($a[0], (int) ($a[1] ?? 0)),
            'min' => min($a), 'max' => max($a),
            'pow' => count($a) === 2 ? $a[0] ** $a[1] : throw new InvalidArgumentException('pow() تأخذ قيمتين.'),
        };
    }
}
