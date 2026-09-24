<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\mailbox\src\Imap\Mime;

/**
 * One node of a parsed MIME tree: either a leaf (has a body) or a container
 * (multipart/*, has children). Header VALUES here are the raw, still
 * RFC2047-encoded text as they appeared on the wire; decoding to readable
 * UTF-8 happens on demand via MimeParser::decodeHeaderValue().
 */
class MimePart
{
    /** @var array<string, string[]> lowercase header name => raw values, in order */
    public array $headers = [];
    public string $contentType = 'text/plain';
    /** @var array<string,string> Content-Type parameters (charset, boundary, name, ...), lowercase keys */
    public array $contentTypeParams = [];
    public ?string $disposition = null;
    /** @var array<string,string> */
    public array $dispositionParams = [];
    public string $encoding = '7bit';
    public ?string $contentId = null;
    public string $rawBody = '';
    /** @var MimePart[] */
    public array $children = [];

    public function header(string $name): ?string
    {
        $v = $this->headers[strtolower($name)] ?? null;
        return $v ? $v[0] : null;
    }

    /** @return string[] */
    public function headerAll(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    public function isMultipart(): bool
    {
        return str_starts_with($this->contentType, 'multipart/');
    }

    public function filename(): ?string
    {
        $name = $this->dispositionParams['filename'] ?? $this->contentTypeParams['name'] ?? null;
        return $name !== null ? MimeParser::decodeHeaderValue($name) : null;
    }

    /**
     * Heuristic used across mail clients: an explicit "attachment" disposition
     * always counts; otherwise a named, non-text, non-container part with no
     * disposition (or "inline" but no Content-ID, i.e. not referenced from HTML)
     * is treated as a downloadable attachment too.
     */
    public function isAttachment(): bool
    {
        if ($this->isMultipart() || $this->contentType === 'message/rfc822') {
            return false;
        }
        if ($this->disposition === 'attachment') {
            return true;
        }
        if ($this->contentId !== null) {
            return false; // referenced inline via cid:, not a generic attachment
        }
        if ($this->disposition === null && $this->filename() !== null && !str_starts_with($this->contentType, 'text/')) {
            return true;
        }
        return false;
    }

    /** Transfer-decoded raw bytes (base64 / quoted-printable undone), no charset conversion. */
    public function decodedBody(): string
    {
        $data = $this->rawBody;
        return match (strtolower($this->encoding)) {
            'base64' => base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $data) ?? '', false) ?: '',
            'quoted-printable' => quoted_printable_decode($data),
            default => $data, // 7bit / 8bit / binary: already raw octets
        };
    }

    /** decodedBody() converted from this part's declared charset to UTF-8. For text/* parts. */
    public function decodedText(): string
    {
        $charset = $this->contentTypeParams['charset'] ?? 'US-ASCII';
        return MimeParser::convertCharset($this->decodedBody(), $charset);
    }

    public function size(): int
    {
        return strlen($this->decodedBody());
    }
}
