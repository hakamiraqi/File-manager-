<?php

namespace App\Services\Exams;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Keeps formatted question text safe: only the tags the editor makes (paragraphs, emphasis,
 * lists, tables, sub/superscript) and the question's own pictures, sound and video. Scripts,
 * styles, links, event handlers and media from anywhere else are dropped.
 */
class HtmlCleaner
{
    private const TAGS = [
        'p', 'br', 'div', 'span', 'b', 'strong', 'i', 'em', 'u', 's', 'sub', 'sup', 'mark',
        'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'h3', 'h4', 'hr',
        'table', 'thead', 'tbody', 'tr', 'th', 'td', 'img', 'audio', 'video',
    ];

    private const ATTRIBUTES = ['dir', 'colspan', 'rowspan', 'src', 'alt', 'controls', 'width', 'class'];

    private const CLASSES = ['text-center', 'text-start', 'text-end'];

    public static function clean(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="root">'.$html.'</div>', LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $doc->getElementById('root') ?? $doc->documentElement;
        self::walk($root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    /** Text only (for search, describing answers, plain exports). */
    public static function text(string $html): string
    {
        return QuestionImporter::plain($html);
    }

    private static function walk(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
                $node->removeChild($child);

                continue;
            }
            if (! $child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'svg', 'math', 'link', 'meta'], true)) {
                $node->removeChild($child);

                continue;
            }
            self::walk($child);
            if (! in_array($tag, self::TAGS, true)) {
                // Keep what is inside an unknown tag, drop the tag.
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);

                continue;
            }
            foreach (iterator_to_array($child->attributes) as $attr) {
                $name = strtolower($attr->name);
                $keep = in_array($name, self::ATTRIBUTES, true)
                    && match ($name) {
                        'src' => in_array($tag, ['img', 'audio', 'video'], true) && self::ownMedia($attr->value),
                        'dir' => in_array($attr->value, ['rtl', 'ltr', 'auto'], true),
                        'colspan', 'rowspan', 'width' => ctype_digit($attr->value) && (int) $attr->value <= 2000,
                        'class' => in_array($attr->value, self::CLASSES, true),
                        default => true,
                    };
                if (! $keep) {
                    $child->removeAttribute($attr->name);
                }
            }
            if (in_array($tag, ['img', 'audio', 'video'], true) && ! $child->hasAttribute('src')) {
                $node->removeChild($child);
            } elseif (in_array($tag, ['audio', 'video'], true)) {
                $child->setAttribute('controls', '');
            }
        }
    }

    /** Media the exam system itself keeps: …/exams/images/<sha256>.<ext>, on this site. */
    private static function ownMedia(string $src): bool
    {
        $path = parse_url($src, PHP_URL_PATH) ?? '';
        $host = parse_url($src, PHP_URL_HOST);

        return ($host === null || $host === parse_url((string) config('app.url'), PHP_URL_HOST))
            && preg_match('#^/exams/images/[a-f0-9]{64}\.[a-z0-9]{2,4}$#', $path) === 1;
    }
}
