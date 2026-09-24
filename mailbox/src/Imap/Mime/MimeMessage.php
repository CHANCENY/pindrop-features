<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\mailbox\src\Imap\Mime;

/** High-level view over a parsed message: headers, body, attachments. */
class MimeMessage
{
    public function __construct(public readonly MimePart $root)
    {
    }

    public static function fromRaw(string $raw): self
    {
        return new self(MimeParser::parse($raw));
    }

    public function header(string $name): ?string
    {
        return $this->root->header($name);
    }

    public function subject(): string
    {
        return MimeParser::decodeHeaderValue($this->header('subject'));
    }

    /** @return array{name:?string,email:string}[] */
    public function from(): array
    {
        return MimeParser::parseAddressList($this->header('from'));
    }

    /** @return array{name:?string,email:string}[] */
    public function to(): array
    {
        return MimeParser::parseAddressList($this->header('to'));
    }

    /** @return array{name:?string,email:string}[] */
    public function cc(): array
    {
        return MimeParser::parseAddressList($this->header('cc'));
    }

    /** @return array{name:?string,email:string}[] */
    public function replyTo(): array
    {
        $v = $this->header('reply-to');
        return $v ? MimeParser::parseAddressList($v) : $this->from();
    }

    public function date(): ?\DateTimeImmutable
    {
        $raw = $this->header('date');
        if (!$raw) {
            return null;
        }
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    public function messageId(): ?string
    {
        $v = $this->header('message-id');
        return $v ? trim($v, "<> \t") : null;
    }

    public function inReplyTo(): ?string
    {
        $v = $this->header('in-reply-to');
        return $v ? trim($v, "<> \t") : null;
    }

    /** @return string[] Message-IDs, oldest first, as used in the References header for threading. */
    public function references(): array
    {
        $v = $this->header('references');
        if (!$v) {
            return [];
        }
        preg_match_all('/<([^<>]+)>/', $v, $m);
        return $m[1] ?? [];
    }

    public function textPlain(): ?string
    {
        $part = $this->findFirst($this->root, fn(MimePart $p) => $p->contentType === 'text/plain' && !$p->isAttachment());
        return $part?->decodedText();
    }

    public function textHtml(): ?string
    {
        $part = $this->findFirst($this->root, fn(MimePart $p) => $p->contentType === 'text/html' && !$p->isAttachment());
        return $part?->decodedText();
    }

    /** Best available body: HTML if present, else plain text escaped by the caller for display. */
    public function preferredBody(): array
    {
        $html = $this->textHtml();
        if ($html !== null) {
            return ['type' => 'html', 'content' => $html];
        }
        return ['type' => 'text', 'content' => $this->textPlain() ?? ''];
    }

    /** A short plain-text preview for message-list rows. */
    public function snippet(int $maxLen = 160): string
    {
        $text = $this->textPlain();
        if ($text === null) {
            $html = $this->textHtml();
            $text = $html !== null ? trim(strip_tags($html)) : '';
        }
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? '';
        return mb_strlen($text) > $maxLen ? mb_substr($text, 0, $maxLen) . '…' : $text;
    }

    /** @return MimePart[] */
    public function attachments(): array
    {
        $out = [];
        $this->walk($this->root, function (MimePart $p) use (&$out) {
            if ($p->isAttachment()) {
                $out[] = $p;
            }
        });
        return $out;
    }

    /** @return array<string, MimePart> Content-ID (without <>) => part, for resolving cid: references in HTML. */
    public function inlineParts(): array
    {
        $out = [];
        $this->walk($this->root, function (MimePart $p) use (&$out) {
            if ($p->contentId !== null) {
                $out[$p->contentId] = $p;
            }
        });
        return $out;
    }

    public function hasAttachments(): bool
    {
        return $this->attachments() !== [];
    }

    private function findFirst(MimePart $part, \Closure $predicate): ?MimePart
    {
        if (!$part->isMultipart()) {
            return $predicate($part) ? $part : null;
        }
        foreach ($part->children as $child) {
            $found = $this->findFirst($child, $predicate);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }

    private function walk(MimePart $part, \Closure $visitor): void
    {
        if ($part->isMultipart()) {
            foreach ($part->children as $child) {
                $this->walk($child, $visitor);
            }
            return;
        }
        $visitor($part);
    }
}
