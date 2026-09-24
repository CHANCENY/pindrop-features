<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\mailbox\src\Imap;

/**
 * Parses one IMAP "response line" into a token tree.
 *
 * Supports the subset of RFC 3501 grammar this client actually needs:
 *   - atoms                 FETCH, FLAGS, \Seen, 12345, NIL
 *   - quoted strings         "some text"   (with \" and \\ escapes)
 *   - literals                {17}\r\n<17 raw bytes>
 *   - parenthesized lists    (...)   — nested, arbitrarily deep
 *   - square-bracket text    [UIDVALIDITY 123]  — kept as a single atom-ish
 *                             token because its *contents* use a different,
 *                             looser grammar (response codes) we don't need
 *                             to parse structurally.
 *
 * NIL is returned as PHP null. Everything else is either a string (atoms,
 * quoted strings, literals) or an array (lists).
 *
 * One response line is tokenized into a flat array of top-level tokens, e.g.
 * the line
 *   * 12 FETCH (UID 44 FLAGS (\Seen) BODY[HEADER] {23}\r\n...23 bytes...)
 * becomes
 *   ['*', '12', 'FETCH', ['UID', '44', 'FLAGS', ['\Seen'], 'BODY[HEADER]', "...23 bytes..."]]
 */
class ImapTokenizer
{
    private string $buf = '';
    private int $pos = 0;

    public function __construct(private readonly ImapStream $stream)
    {
    }

    /** Read one full response line (following literals as needed) and tokenize it. */
    public function nextResponse(): array
    {
        $this->buf = $this->stream->readLine();
        $this->pos = 0;
        $tokens = [];
        while (true) {
            $this->skipSpaces();
            if ($this->pos >= strlen($this->buf)) {
                break;
            }
            $tokens[] = $this->readToken();
        }
        return $tokens;
    }

    private function skipSpaces(): void
    {
        while ($this->pos < strlen($this->buf) && $this->buf[$this->pos] === ' ') {
            $this->pos++;
        }
    }

    /** @return string|array|null */
    private function readToken(): string|array|null
    {
        $c = $this->buf[$this->pos] ?? '';

        if ($c === '(') {
            return $this->readList();
        }
        if ($c === '"') {
            return $this->readQuoted();
        }
        if ($c === '{') {
            return $this->readLiteral();
        }
        if ($c === '[') {
            return $this->readBracketed();
        }
        // An atom immediately followed by "[...]" (e.g. BODY[HEADER.FIELDS (FROM)])
        // is one compound token in FETCH responses — concatenate them.
        $atom = $this->readAtom();
        if (($this->buf[$this->pos] ?? '') === '[') {
            $atom = ($atom ?? '') . $this->readBracketed();
        }
        return $atom;
    }

    private function readList(): array
    {
        $this->pos++; // consume '('
        $items = [];
        while (true) {
            $this->skipSpaces();
            if ($this->pos >= strlen($this->buf)) {
                // List spans onto a continuation line (rare, but literals inside
                // a list can leave us mid-line after readLiteral appended more buffer).
                throw new ImapException('Unterminated list in IMAP response.', ImapException::PROTOCOL);
            }
            if ($this->buf[$this->pos] === ')') {
                $this->pos++;
                break;
            }
            $items[] = $this->readToken();
        }
        return $items;
    }

    private function readBracketed(): string
    {
        $start = $this->pos;
        $depth = 0;
        while ($this->pos < strlen($this->buf)) {
            $ch = $this->buf[$this->pos];
            if ($ch === '[') {
                $depth++;
            } elseif ($ch === ']') {
                $depth--;
                $this->pos++;
                if ($depth === 0) {
                    return substr($this->buf, $start, $this->pos - $start);
                }
                continue;
            }
            $this->pos++;
        }
        throw new ImapException('Unterminated [ ] section in IMAP response.', ImapException::PROTOCOL);
    }

    private function readQuoted(): string
    {
        $this->pos++; // opening quote
        $out = '';
        while (true) {
            if ($this->pos >= strlen($this->buf)) {
                throw new ImapException('Unterminated quoted string in IMAP response.', ImapException::PROTOCOL);
            }
            $ch = $this->buf[$this->pos];
            if ($ch === '\\') {
                $next = $this->buf[$this->pos + 1] ?? '';
                $out .= $next;
                $this->pos += 2;
                continue;
            }
            if ($ch === '"') {
                $this->pos++;
                return $out;
            }
            $out .= $ch;
            $this->pos++;
        }
    }

    /** {N}\r\n<N bytes> — the {N} sits at the end of the current line. */
    private function readLiteral(): string
    {
        $end = strpos($this->buf, '}', $this->pos);
        if ($end === false) {
            throw new ImapException('Malformed literal marker in IMAP response.', ImapException::PROTOCOL);
        }
        $nStr = substr($this->buf, $this->pos + 1, $end - $this->pos - 1);
        $nStr = rtrim($nStr, '+'); // non-synchronising literal {N+}
        if (!ctype_digit($nStr)) {
            throw new ImapException('Malformed literal length in IMAP response.', ImapException::PROTOCOL);
        }
        $n = (int) $nStr;

        // Everything after "{N}" on this line must be empty per the grammar;
        // the literal bytes start on the NEXT line.
        $data = $this->stream->readBytes($n);

        // The literal's bytes are immediately followed by the rest of the
        // logical response line (whatever came after "{N}" in the original
        // grammar), itself terminated by its own CRLF. Splice: drop the
        // "{N}" marker (everything from the current position onward) and
        // append that continuation line in its place. $this->pos is left
        // unchanged, which now correctly points at the start of that
        // continuation, ready for the next readToken() call.
        $tail = $this->stream->readLine();
        $this->buf = substr($this->buf, 0, $this->pos) . $tail;
        return $data;
    }

    private function readAtom(): string
    {
        $start = $this->pos;
        while ($this->pos < strlen($this->buf)) {
            $ch = $this->buf[$this->pos];
            if ($ch === ' ' || $ch === '(' || $ch === ')' || $ch === '"' || $ch === '[') {
                break;
            }
            $this->pos++;
        }
        $atom = substr($this->buf, $start, $this->pos - $start);
        return $atom === 'NIL' ? null : $atom;
    }
}
