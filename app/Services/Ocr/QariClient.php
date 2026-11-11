<?php

namespace App\Services\Ocr;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Qari-OCR (NAMAA-Space), an Arabic text-recognition model, on trial (2026-11-11, by request):
 * served by llama.cpp in its own containers on the server, 8-bit (Q8_0 GGUF), reachable only
 * from FileNest's network. Two sizes: 2B (0.2.2.1, typed documents) and 4B (0.4.0, books).
 */
class QariClient
{
    /** Model key => [label, config key]. */
    public const MODELS = [
        'qari-2b' => ['Qari 2B (0.2.2.1)', 'services.qari.2b'],
        'qari-4b' => ['Qari 4B (0.4.0)', 'services.qari.4b'],
    ];

    /** The prompt from the model card. */
    private const PROMPT = 'Below is the image of one page of a document, as well as some raw textual content that was previously extracted for it. Just return the plain text representation of this document as if you were reading it naturally. Do not hallucinate.';

    public function url(string $model): ?string
    {
        $key = self::MODELS[$model][1] ?? null;

        return $key ? rtrim((string) config($key), '/') ?: null : null;
    }

    /** Is the model's server up (and has it finished loading)? */
    public function health(string $model): bool
    {
        $url = $this->url($model);
        if (! $url) {
            return false;
        }
        try {
            return Http::timeout(3)->get("$url/health")->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Reads one page picture. Returns the text and the server's timings (seconds).
     *
     * @return array{text: string, seconds: float, prompt_tokens: ?int, tokens: ?int}
     */
    public function read(string $model, string $imagePath, int $maxSide = 1600): array
    {
        $url = $this->url($model) ?? throw new RuntimeException("لا يوجد عنوان للنموذج $model.");
        $jpeg = $this->scaled($imagePath, $maxSide);
        $start = microtime(true);
        $r = Http::timeout(1800)->post("$url/v1/chat/completions", [
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,'.base64_encode($jpeg)]],
                    ['type' => 'text', 'text' => self::PROMPT],
                ],
            ]],
            'max_tokens' => 2000,
            'temperature' => 0,
        ]);
        if (! $r->successful()) {
            throw new RuntimeException('Qari: '.mb_substr((string) ($r->json('error.message') ?? $r->body()), 0, 300));
        }

        return [
            'text' => trim((string) $r->json('choices.0.message.content')),
            'seconds' => round(microtime(true) - $start, 1),
            'prompt_tokens' => $r->json('usage.prompt_tokens'),
            'tokens' => $r->json('usage.completion_tokens'),
        ];
    }

    /** The page as a JPEG no larger than $maxSide (bigger pictures cost much more time). */
    private function scaled(string $path, int $maxSide): string
    {
        $data = file_get_contents($path);
        $img = @imagecreatefromstring($data);
        if ($img === false) {
            throw new RuntimeException('تعذّرت قراءة الصورة.');
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1, $maxSide / max($w, $h));
        if ($scale < 1) {
            $out = imagecreatetruecolor((int) round($w * $scale), (int) round($h * $scale));
            imagecopyresampled($out, $img, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
            imagedestroy($img);
            $img = $out;
        }
        ob_start();
        imagejpeg($img, null, 90);
        imagedestroy($img);

        return (string) ob_get_clean();
    }
}
