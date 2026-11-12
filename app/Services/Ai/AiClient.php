<?php

namespace App\Services\Ai;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Claude (Anthropic API) for the reader's assistant (2026-11-12, phase 4): summaries,
 * translation, explaining a term and questions about a file. On when an administrator saved an
 * API key in the settings; it needs the internet, so on the offline server it works only while
 * that computer is online. Only the text being asked about is sent, never who is asking.
 */
class AiClient
{
    public const DEFAULT_MODEL = 'claude-sonnet-5-5';

    public static function enabled(): bool
    {
        return filled(Setting::get('ai_api_key'));
    }

    /**
     * @param  list<array{role:string,content:string}>  $messages
     */
    public function ask(string $system, array $messages, int $maxTokens = 1500, int $timeout = 120): string
    {
        if (! self::enabled()) {
            throw new RuntimeException('المساعد الذكي غير مفعّل. يضيف المدير مفتاحه في الإعدادات.');
        }
        try {
            $key = Crypt::decryptString((string) Setting::get('ai_api_key'));
        } catch (Throwable) {
            throw new RuntimeException('مفتاح الذكاء الاصطناعي المحفوظ غير صالح؛ أعد حفظه في الإعدادات.');
        }
        try {
            $response = Http::timeout($timeout)->withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
            ])->post('https://api.anthropic.com/v1/messages', [
                'model' => Setting::get('ai_model') ?: self::DEFAULT_MODEL,
                'max_tokens' => $maxTokens,
                'system' => $system,
                'messages' => $messages,
            ]);
        } catch (Throwable) {
            throw new RuntimeException('تعذّر الوصول إلى خدمة الذكاء الاصطناعي؛ تحقق من اتصال الخادم بالإنترنت.');
        }
        if ($response->status() === 401) {
            throw new RuntimeException('مفتاح الذكاء الاصطناعي مرفوض؛ راجعه في الإعدادات.');
        }
        if ($response->status() === 429) {
            throw new RuntimeException('الخدمة مشغولة الآن؛ حاول بعد قليل.');
        }
        if ($response->failed()) {
            throw new RuntimeException('تعذّر الاتصال بخدمة الذكاء الاصطناعي ('.$response->status().').');
        }
        $text = collect($response->json('content') ?? [])->where('type', 'text')->pluck('text')->implode("\n");

        return trim($text);
    }
}
