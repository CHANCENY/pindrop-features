<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\qa\src\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class QaTwigExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('qa_time_ago', [$this, 'timeAgo']),
            new TwigFilter('qa_excerpt', [$this, 'excerpt']),
            // Body is stored as sanitized HTML from the rich editor (see README
            // re: editor integration) — this filter only escapes fallback plain
            // text paths, it does not itself sanitize; sanitization happens on
            // submit, not on render.
            new TwigFilter('qa_markdown', [$this, 'markdownLite'], ['is_safe' => ['html']]),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('qa_json_ld', [$this, 'jsonLd'], ['is_safe' => ['html']]),
        ];
    }

    public function timeAgo(mixed $datetime): string
    {
        try {
            $timestamp = $datetime instanceof \DateTimeInterface ? $datetime->getTimestamp() : strtotime((string) $datetime);
            $diff = time() - $timestamp;

            if ($diff < 60) {
                return 'just now';
            }
            $units = [
                31536000 => 'year', 2592000 => 'month', 86400 => 'day',
                3600 => 'hour', 60 => 'minute',
            ];
            foreach ($units as $seconds => $label) {
                $count = intdiv($diff, $seconds);
                if ($count >= 1) {
                    return $count . ' ' . $label . ($count > 1 ? 's' : '') . ' ago';
                }
            }
            return 'just now';
        } catch (\Exception) {
            return '';
        }
    }

    public function excerpt(?string $html, int $length = 160): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $html)) ?? '');
        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1) . '…' : $text;
    }

    /**
     * Very small Markdown-ish -> HTML pass for plain-text fallback content
     * (bold/italic/code/links/line breaks). Question/answer bodies produced
     * by the rich editor should already be sanitized HTML; this filter is
     * for anywhere plain text needs light formatting (e.g. an RSS preview).
     */
    public function markdownLite(?string $text): string
    {
        $text = htmlspecialchars((string) $text, ENT_QUOTES);
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace('/`(.+?)`/', '<code>$1</code>', $text) ?? $text;
        $text = nl2br($text);
        return $text;
    }

    public function jsonLd(array $data): string
    {
        return '<script type="application/ld+json">'
            . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . '</script>';
    }
}
