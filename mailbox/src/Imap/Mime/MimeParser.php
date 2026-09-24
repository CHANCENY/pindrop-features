<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\mailbox\src\Imap\Mime;

/**
 * Parses a raw RFC 822/2045/2046 message (as fetched whole from IMAP via
 * BODY.PEEK[]) into a MimePart tree, and provides the header/charset/address
 * decoding helpers used throughout the plugin.
 */
class MimeParser
{
    public static function parse(string $raw): MimePart
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $raw = str_replace("\r", "\n", $raw);
        return self::parsePart($raw);
    }

    private static function parsePart(string $raw): MimePart
    {
        $part = new MimePart();
        [$headerBlock, $body] = self::splitHeaderBody($raw);
        $part->headers = self::parseHeaders($headerBlock);

        [$ct, $ctParams] = self::parseHeaderWithParams($part->header('content-type') ?? 'text/plain; charset=us-ascii');
        $part->contentType = strtolower($ct ?: 'text/plain');
        $part->contentTypeParams = $ctParams;
        if (!isset($part->contentTypeParams['charset']) && str_starts_with($part->contentType, 'text/')) {
            $part->contentTypeParams['charset'] = 'US-ASCII';
        }

        $part->encoding = strtolower(trim($part->header('content-transfer-encoding') ?? '7bit'));

        if ($cd = $part->header('content-disposition')) {
            [$disp, $dispParams] = self::parseHeaderWithParams($cd);
            $part->disposition = $disp !== null && $disp !== '' ? strtolower($disp) : null;
            $part->dispositionParams = $dispParams;
        }

        if ($cid = $part->header('content-id')) {
            $part->contentId = trim($cid, "<> \t");
        }

        if ($part->isMultipart() && isset($part->contentTypeParams['boundary']) && $part->contentTypeParams['boundary'] !== '') {
            $part->children = self::splitMultipart($body, $part->contentTypeParams['boundary']);
        } else {
            $part->rawBody = $body;
        }
        return $part;
    }

    /** @return array{0:string,1:string} [headerBlock, body] */
    private static function splitHeaderBody(string $raw): array
    {
        $pos = strpos($raw, "\n\n");
        if ($pos === false) {
            return [$raw, ''];
        }
        return [substr($raw, 0, $pos), substr($raw, $pos + 2)];
    }

    /** @return array<string,string[]> lowercase name => raw values, folded lines joined */
    private static function parseHeaders(string $block): array
    {
        if (trim($block) === '') {
            return [];
        }
        // Unfold: a line starting with space/tab continues the previous header.
        $lines = explode("\n", $block);
        $unfolded = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            if (($line[0] === ' ' || $line[0] === "\t") && $unfolded !== []) {
                $unfolded[count($unfolded) - 1] .= ' ' . trim($line);
            } else {
                $unfolded[] = $line;
            }
        }
        $headers = [];
        foreach ($unfolded as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue; // malformed line, ignore rather than fail the whole message
            }
            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));
            $headers[$name][] = $value;
        }
        return $headers;
    }

    /**
     * Parses "value; param1=val1; param2=\"val 2\"" into [value, params].
     * Handles RFC 2231 continuation/extended params (param*0=, param*=charset'lang'value) minimally.
     */
    private static function parseHeaderWithParams(string $raw): array
    {
        $parts = self::splitOutsideQuotes($raw, ';');
        $value = trim($parts[0] ?? '');
        $value = trim($value, "\"'");
        $params = [];
        $continuations = [];
        for ($i = 1; $i < count($parts); $i++) {
            $p = trim($parts[$i]);
            if ($p === '' || !str_contains($p, '=')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode('=', $p, 2));
            $v = trim($v, "\"");
            $k = strtolower($k);
            if (str_contains($k, '*')) {
                // RFC 2231: name*0="a"; name*1="b"  or  name*=UTF-8''value
                [$base] = explode('*', $k, 2);
                if (preg_match("/^.*''(.*)$/", $v, $m)) {
                    $v = rawurldecode($m[1]);
                }
                $continuations[$base] = ($continuations[$base] ?? '') . $v;
                continue;
            }
            $params[$k] = $v;
        }
        foreach ($continuations as $k => $v) {
            $params[$k] = $v;
        }
        return [$value, $params];
    }

    /** Splits on $delim but not inside "..." quotes. */
    private static function splitOutsideQuotes(string $s, string $delim): array
    {
        $out = [];
        $buf = '';
        $inQuotes = false;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === '"') {
                $inQuotes = !$inQuotes;
                $buf .= $c;
                continue;
            }
            if ($c === $delim && !$inQuotes) {
                $out[] = $buf;
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        $out[] = $buf;
        return $out;
    }

    /** @return MimePart[] */
    private static function splitMultipart(string $body, string $boundary): array
    {
        $delim = '--' . $boundary;
        $lines = explode("\n", $body);
        $inPart = false;
        $buffer = [];
        $texts = [];
        foreach ($lines as $line) {
            $trimmed = rtrim($line, "\r");
            if ($trimmed === $delim || $trimmed === $delim . '--') {
                if ($inPart) {
                    $texts[] = implode("\n", $buffer);
                }
                $buffer = [];
                if ($trimmed === $delim . '--') {
                    $inPart = false;
                    break; // closing boundary: stop, discard any epilogue
                }
                $inPart = true;
                continue;
            }
            if ($inPart) {
                $buffer[] = $line;
            }
            // else: preamble text before the first boundary — discarded
        }
        return array_map(fn(string $text) => self::parsePart($text), $texts);
    }

    // ------------------------------------------------------------------
    // Header value / charset / address decoding (used by MimeMessage too)
    // ------------------------------------------------------------------

    /** Decodes RFC 2047 encoded-words ("=?UTF-8?B?...?=") in a header value to a UTF-8 string. */
    public static function decodeHeaderValue(?string $raw): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }
        if (!str_contains($raw, '=?')) {
            // No RFC2047 encoded-words present. mb_decode_mimeheader() can
            // corrupt already-valid UTF-8 text in this case (it runs the
            // whole string through mbstring's internal-encoding conversion
            // regardless), so skip it entirely and just normalise to UTF-8.
            return self::forceUtf8($raw);
        }
        if (function_exists('mb_decode_mimeheader')) {
            $decoded = @mb_decode_mimeheader($raw);
            if (is_string($decoded) && $decoded !== '') {
                return self::forceUtf8($decoded);
            }
        }
        // Manual fallback: decode each =?charset?(B|Q)?...?= token.
        $out = preg_replace_callback(
            '/=\?([^?]+)\?([bBqQ])\?([^?]*)\?=/',
            function (array $m) {
                $charset = $m[1];
                $enc = strtoupper($m[2]);
                $text = $m[3];
                $bytes = $enc === 'B'
                    ? (base64_decode($text, true) ?: '')
                    : quoted_printable_decode(str_replace('_', ' ', $text));
                return self::convertCharset($bytes, $charset);
            },
            $raw
        );
        return self::forceUtf8((string) ($out ?? $raw));
    }

    public static function convertCharset(string $data, string $charset): string
    {
        $charset = trim($charset, "\" \t");
        if ($charset === '' || strcasecmp($charset, 'UTF-8') === 0 || strcasecmp($charset, 'US-ASCII') === 0) {
            return self::forceUtf8($data);
        }
        if (function_exists('mb_convert_encoding')) {
            $out = @mb_convert_encoding($data, 'UTF-8', $charset);
            if (is_string($out) && $out !== '') {
                return $out;
            }
        }
        if (function_exists('iconv')) {
            $out = @iconv($charset, 'UTF-8//TRANSLIT//IGNORE', $data);
            if (is_string($out)) {
                return $out;
            }
        }
        return self::forceUtf8($data);
    }

    private static function forceUtf8(string $s): string
    {
        if ($s === '' || preg_match('//u', $s) === 1) {
            return $s;
        }
        $out = function_exists('mb_convert_encoding') ? @mb_convert_encoding($s, 'UTF-8', 'UTF-8') : false;
        return is_string($out) ? $out : (@iconv('UTF-8', 'UTF-8//IGNORE', $s) ?: '');
    }

    /**
     * Parses an address-list header (From/To/Cc/Bcc) into [['name'=>?, 'email'=>string], ...].
     * Handles: "Display Name" <a@b>, Display Name <a@b>, a@b, and comma-separated lists,
     * respecting commas inside quoted display names.
     */
    public static function parseAddressList(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        $entries = self::splitOutsideQuotes($raw, ',');
        $out = [];
        foreach ($entries as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (preg_match('/^(.*)<([^<>]+)>\s*$/', $entry, $m)) {
                $name = trim($m[1], " \t\"");
                $email = trim($m[2]);
                $out[] = ['name' => $name !== '' ? self::decodeHeaderValue($name) : null, 'email' => $email];
            } elseif (filter_var($entry, FILTER_VALIDATE_EMAIL) !== false) {
                $out[] = ['name' => null, 'email' => $entry];
            } elseif ($entry !== '') {
                // Malformed but non-empty (e.g. a bare group name like "undisclosed-recipients:;") — keep as display-only.
                $out[] = ['name' => self::decodeHeaderValue(rtrim($entry, ':;')), 'email' => ''];
            }
        }
        return $out;
    }

    public static function formatAddress(array $addr): string
    {
        $email = $addr['email'] ?? '';
        $name = $addr['name'] ?? null;
        return $name ? ($name . ($email !== '' ? ' <' . $email . '>' : '')) : $email;
    }
}
