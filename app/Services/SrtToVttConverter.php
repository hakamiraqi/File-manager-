<?php

namespace App\Services;

/**
 * Converts SubRip (.srt) subtitle text to WebVTT, the only subtitle format
 * browsers understand natively via <track>. Pure PHP/regex — no external
 * binary required, unlike the ffmpeg-dependent video features, so this
 * always works regardless of what's installed on the server.
 */
class SrtToVttConverter
{
    public function convert(string $srt): string
    {
        // SRT uses a comma for the millisecond separator, WebVTT a dot.
        // WebVTT also requires cue text blocks to be UTF-8 with the file
        // starting with the literal "WEBVTT" header line.
        $body = str_replace("\r\n", "\n", $srt);
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body); // strip UTF-8 BOM if present
        $body = preg_replace('/(\d{2}:\d{2}:\d{2}),(\d{3})/', '$1.$2', $body);
        // Numeric cue-index lines (a bare integer on its own line) are
        // optional in WebVTT and safe to drop to avoid confusing some
        // players that expect the first non-blank line of a cue to be a
        // timestamp range.
        $body = preg_replace('/^\d+\s*$/m', '', $body);
        $body = trim($body);

        return "WEBVTT\n\n{$body}\n";
    }

    public function looksLikeSrt(string $content): bool
    {
        return (bool) preg_match('/\d{2}:\d{2}:\d{2}[,.]\d{3}\s*-->\s*\d{2}:\d{2}:\d{2}[,.]\d{3}/', $content);
    }
}
