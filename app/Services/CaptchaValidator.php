<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Two-tier captcha: if reCAPTCHA keys are configured and enabled, verify the
 * Google reCAPTCHA v2/invisible token; otherwise fall back to a lightweight
 * server-side math challenge (no external service or image library needed).
 */
class CaptchaValidator
{
    public function isRecaptchaEnabled(): bool
    {
        return (bool) Setting::get('recaptcha_enabled', false)
            && (string) Setting::get('recaptcha_site_key', '') !== '';
    }

    public function siteKey(): ?string
    {
        return Setting::get('recaptcha_site_key') ?: null;
    }

    public function isInvisible(): bool
    {
        return (bool) Setting::get('recaptcha_invisible', false);
    }

    /**
     * A fresh math challenge to render on the form. The answer is kept only
     * in the session, never sent to the client.
     *
     * @return array{question: string}
     */
    public function generateMathChallenge(Request $request): array
    {
        $a = random_int(1, 9);
        $b = random_int(1, 9);
        $request->session()->put('captcha_answer', $a + $b);

        return ['question' => "{$a} + {$b}"];
    }

    public function assertValid(Request $request): void
    {
        if ($this->isRecaptchaEnabled()) {
            $this->assertRecaptchaValid($request);

            return;
        }

        $answer = $request->session()->pull('captcha_answer');
        $submitted = (int) $request->input('captcha_answer');

        if ($answer === null || $submitted !== (int) $answer) {
            throw ValidationException::withMessages(['captcha_answer' => 'إجابة رمز التحقق غير صحيحة.']);
        }
    }

    private function assertRecaptchaValid(Request $request): void
    {
        $token = (string) $request->input('g-recaptcha-response');
        $secret = (string) Setting::get('recaptcha_secret_key', '');

        if ($token === '' || $secret === '') {
            throw ValidationException::withMessages(['captcha_answer' => 'الرجاء إكمال التحقق الأمني.']);
        }

        try {
            $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $request->ip(),
            ]);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['captcha_answer' => 'تعذّر التحقق الأمني، الرجاء المحاولة لاحقاً.']);
        }

        if (! $response->successful() || ! ($response->json('success') ?? false)) {
            throw ValidationException::withMessages(['captcha_answer' => 'فشل التحقق الأمني، الرجاء المحاولة مجدداً.']);
        }
    }
}
