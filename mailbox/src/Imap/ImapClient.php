<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\mailbox\src\Imap;

/**
 * Dependency-free IMAP4rev1 client (RFC 3501) over a raw TLS/plain socket.
 *
 * Deliberately does NOT use ext-imap: that extension was pulled from PHP core
 * as of 8.4 (moved to PECL, rarely installed), so relying on it would make
 * this plugin fail to install on a stock PHP 8.4 server. This class only
 * needs stream_socket_client(), which is always available.
 *
 * Design choice: for message bodies, this client always fetches the RAW
 * RFC822 bytes (BODY.PEEK[] / BODY[]) rather than asking the server for a
 * BODYSTRUCTURE and fetching individual MIME parts. BODYSTRUCTURE parsing is
 * notoriously inconsistent across server implementations (Dovecot, Exchange,
 * Gmail all have quirks); fetching the whole message and parsing MIME
 * ourselves (see Mime\MimeParser) is slightly more bandwidth but vastly more
 * portable and testable offline.
 */
class ImapClient implements ImapClientInterface
{
    private ImapStream $stream;
    private ImapTokenizer $tokenizer;
    private int $tagCounter = 0;
    private array $capabilities = [];
    private ?string $selectedMailbox = null;
    private bool $selectedReadOnly = false;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption, // 'ssl' | 'tls' | 'none'
        private readonly float $timeout = 20.0
    ) {
    }

    public function connect(): void
    {
        $this->stream = new ImapStream($this->host, $this->port, $this->encryption, $this->timeout);
        $this->tokenizer = new ImapTokenizer($this->stream);

        // Greeting: * OK ...
        $greeting = $this->tokenizer->nextResponse();
        if (($greeting[0] ?? null) !== '*') {
            throw new ImapException('Unexpected server greeting.', ImapException::PROTOCOL);
        }
        if (strtoupper((string) ($greeting[1] ?? '')) === 'BYE') {
            throw new ImapException('Server rejected the connection: ' . $this->joinRest($greeting, 2), ImapException::CONNECT);
        }
    }

    public function login(string $username, string $password): void
    {
        // Login is sent as two literals so no character in the username/password
        // (quotes, backslashes, non-ASCII) needs escaping or can break the command.
        // Each literal needs its OWN "+" continuation prompt from the server —
        // they cannot both be queued on the initial command line.
        $tag = $this->nextTag();
        $this->stream->write($tag . ' LOGIN {' . strlen($username) . "}\r\n");
        $this->expectContinuation();
        $this->stream->write($username);
        $this->stream->write(' {' . strlen($password) . "}\r\n");
        $this->expectContinuation();
        $this->stream->write($password . "\r\n");
        $result = $this->readUntilTagged($tag);
        if ($result['status'] !== 'OK') {
            throw new ImapException('Login failed: ' . $result['text'], ImapException::AUTH);
        }
        $this->capability(); // refresh capabilities post-auth (some servers change them)
    }

    public function capability(): array
    {
        $tag = $this->nextTag();
        $this->stream->write($tag . " CAPABILITY\r\n");
        $lines = $this->collectUntilTagged($tag);
        foreach ($lines as $line) {
            if (($line[0] ?? null) === '*' && strtoupper((string) ($line[1] ?? '')) === 'CAPABILITY') {
                $this->capabilities = array_map('strtoupper', array_filter(array_slice($line, 2), 'is_string'));
            }
        }
        return $this->capabilities;
    }

    public function supports(string $capability): bool
    {
        return in_array(strtoupper($capability), $this->capabilities, true);
    }

    /**
     * @return array<int, array{name:string, delimiter:?string, flags:string[]}>
     */
    public function listMailboxes(string $reference = '', string $pattern = '*'): array
    {
        $tag = $this->nextTag();
        $this->stream->write($tag . ' LIST ' . $this->quote($reference) . ' ' . $this->quote($pattern) . "\r\n");
        $lines = $this->collectUntilTagged($tag);
        $this->assertOk($lines, $tag, 'LIST failed');

        $out = [];
        foreach ($lines as $line) {
            if (($line[0] ?? null) === '*' && strtoupper((string) ($line[1] ?? '')) === 'LIST') {
                $flags = array_map('strval', $line[2] ?? []);
                $delimiter = $line[3] ?? null;
                $name = $line[4] ?? '';
                $out[] = [
                    'name' => $this->utf7ImapDecode((string) $name),
                    'raw_name' => (string) $name,
                    'delimiter' => $delimiter,
                    'flags' => $flags,
                    'selectable' => !in_array('\\Noselect', $flags, true),
                ];
            }
        }
        return $out;
    }

    public function createMailbox(string $rawName): void
    {
        $tag = $this->nextTag();
        $this->stream->write($tag . ' CREATE ' . $this->quote($rawName) . "\r\n");
        $result = $this->readUntilTagged($tag);
        if ($result['status'] !== 'OK') {
            throw new ImapException('Could not create folder: ' . $result['text'], $result['status'] === 'NO' ? ImapException::NO : ImapException::BAD);
        }
    }

    public function deleteMailbox(string $rawName): void
    {
        $tag = $this->nextTag();
        $this->stream->write($tag . ' DELETE ' . $this->quote($rawName) . "\r\n");
        $result = $this->readUntilTagged($tag);
        if ($result['status'] !== 'OK') {
            throw new ImapException('Could not delete folder: ' . $result['text'], ImapException::NO);
        }
    }

    public function renameMailbox(string $rawOld, string $rawNew): void
    {
        $tag = $this->nextTag();
        $this->stream->write($tag . ' RENAME ' . $this->quote($rawOld) . ' ' . $this->quote($rawNew) . "\r\n");
        $result = $this->readUntilTagged($tag);
        if ($result['status'] !== 'OK') {
            throw new ImapException('Could not rename folder: ' . $result['text'], ImapException::NO);
        }
    }

    /** @return array{exists:int, recent:int, unseen:int, uidvalidity:int, uidnext:int, flags:string[], permanentFlags:string[]} */
    public function select(string $rawMailbox, bool $readOnly = false): array
    {
        $tag = $this->nextTag();
        $cmd = $readOnly ? 'EXAMINE' : 'SELECT';
        $this->stream->write($tag . ' ' . $cmd . ' ' . $this->quote($rawMailbox) . "\r\n");
        $lines = $this->collectUntilTagged($tag);
        $this->assertOk($lines, $tag, "$cmd failed");

        $info = ['exists' => 0, 'recent' => 0, 'unseen' => 0, 'uidvalidity' => 0, 'uidnext' => 0, 'flags' => [], 'permanentFlags' => []];
        foreach ($lines as $line) {
            if (($line[0] ?? null) !== '*') {
                continue;
            }
            if (is_numeric($line[1] ?? null) && strtoupper((string) ($line[2] ?? '')) === 'EXISTS') {
                $info['exists'] = (int) $line[1];
            } elseif (is_numeric($line[1] ?? null) && strtoupper((string) ($line[2] ?? '')) === 'RECENT') {
                $info['recent'] = (int) $line[1];
            } elseif (strtoupper((string) ($line[1] ?? '')) === 'FLAGS') {
                $info['flags'] = array_map('strval', $line[2] ?? []);
            } elseif (($line[1] ?? '') === 'OK' && isset($line[2]) && is_string($line[2])) {
                $code = $line[2]; // e.g. "[UIDVALIDITY 123]"
                if (preg_match('/\[UNSEEN\s+(\d+)]/i', $code, $m)) {
                    $info['unseen'] = (int) $m[1];
                } elseif (preg_match('/\[UIDVALIDITY\s+(\d+)]/i', $code, $m)) {
                    $info['uidvalidity'] = (int) $m[1];
                } elseif (preg_match('/\[UIDNEXT\s+(\d+)]/i', $code, $m)) {
                    $info['uidnext'] = (int) $m[1];
                } elseif (preg_match('/\[PERMANENTFLAGS\s*\(([^)]*)\)]/i', $code, $m)) {
                    $info['permanentFlags'] = array_values(array_filter(explode(' ', $m[1])));
                }
            }
        }
        $this->selectedMailbox = $rawMailbox;
        $this->selectedReadOnly = $readOnly;
        return $info;
    }

    /** UID SEARCH. $criteria is a raw IMAP search-key string, e.g. 'UID 100:*' or 'UNSEEN' or 'HEADER SUBJECT "invoice"'. */
    public function uidSearch(string $criteria = 'ALL'): array
    {
        $this->assertSelected();
        $tag = $this->nextTag();
        $this->stream->write($tag . ' UID SEARCH ' . $criteria . "\r\n");
        $lines = $this->collectUntilTagged($tag);
        $this->assertOk($lines, $tag, 'SEARCH failed');

        $uids = [];
        foreach ($lines as $line) {
            if (($line[0] ?? null) === '*' && strtoupper((string) ($line[1] ?? '')) === 'SEARCH') {
                foreach (array_slice($line, 2) as $n) {
                    if (is_string($n) && ctype_digit($n)) {
                        $uids[] = (int) $n;
                    }
                }
            }
        }
        return $uids;
    }

    /**
     * UID FETCH for a set of UIDs. $items is a raw fetch-items string, e.g.
     * 'FLAGS INTERNALDATE RFC822.SIZE BODY.PEEK[HEADER.FIELDS (FROM TO CC SUBJECT DATE MESSAGE-ID)]'
     * or 'BODY.PEEK[]' for the whole raw message.
     *
     * @return array<int, array<string, mixed>> keyed by UID
     */
    public function uidFetch(array $uids, string $items): array
    {
        $this->assertSelected();
        if ($uids === []) {
            return [];
        }
        $tag = $this->nextTag();
        $set = $this->uidSet($uids);
        $this->stream->write($tag . ' UID FETCH ' . $set . ' (UID ' . $items . ")\r\n");
        $lines = $this->collectUntilTagged($tag);
        $this->assertOk($lines, $tag, 'FETCH failed');

        $out = [];
        foreach ($lines as $line) {
            if (($line[0] ?? null) !== '*' || strtoupper((string) ($line[2] ?? '')) !== 'FETCH') {
                continue;
            }
            $pairs = $line[3] ?? [];
            $row = [];
            for ($i = 0; $i < count($pairs) - 1; $i += 2) {
                $key = strtoupper((string) $pairs[$i]);
                $row[$key] = $pairs[$i + 1];
            }
            $uid = isset($row['UID']) ? (int) $row['UID'] : null;
            if ($uid !== null) {
                $out[$uid] = $row;
            }
        }
        return $out;
    }

    /** UID STORE — add/remove/replace flags. $mode is '+', '-', or ''. */
    public function uidStore(array $uids, string $mode, array $flags, bool $silent = true): void
    {
        $this->assertSelected();
        if ($uids === []) {
            return;
        }
        if ($this->selectedReadOnly) {
            throw new ImapException('Mailbox is selected read-only; cannot change flags.', ImapException::BAD);
        }
        $tag = $this->nextTag();
        $flagList = '(' . implode(' ', $flags) . ')';
        $item = $mode . 'FLAGS' . ($silent ? '.SILENT' : '');
        $this->stream->write($tag . ' UID STORE ' . $this->uidSet($uids) . ' ' . $item . ' ' . $flagList . "\r\n");
        $lines = $this->collectUntilTagged($tag);
        $this->assertOk($lines, $tag, 'STORE failed');
    }

    public function uidCopy(array $uids, string $rawDestMailbox): void
    {
        $this->assertSelected();
        if ($uids === []) {
            return;
        }
        $tag = $this->nextTag();
        $this->stream->write($tag . ' UID COPY ' . $this->uidSet($uids) . ' ' . $this->quote($rawDestMailbox) . "\r\n");
        $result = $this->readUntilTagged($tag);
        if ($result['status'] !== 'OK') {
            throw new ImapException('Copy failed: ' . $result['text'], ImapException::NO);
        }
    }

    /** True IMAP MOVE (RFC 6851) if the server advertises it. */
    public function uidMove(array $uids, string $rawDestMailbox): void
    {
        $this->assertSelected();
        if ($uids === []) {
            return;
        }
        $tag = $this->nextTag();
        $this->stream->write($tag . ' UID MOVE ' . $this->uidSet($uids) . ' ' . $this->quote($rawDestMailbox) . "\r\n");
        $result = $this->readUntilTagged($tag);
        if ($result['status'] !== 'OK') {
            throw new ImapException('Move failed: ' . $result['text'], ImapException::NO);
        }
    }

    /** Fallback move for servers without MOVE: copy, mark \Deleted, expunge just those UIDs. */
    public function uidMoveFallback(array $uids, string $rawDestMailbox): void
    {
        $this->uidCopy($uids, $rawDestMailbox);
        $this->uidStore($uids, '+', ['\\Deleted']);
        $this->uidExpunge($uids);
    }

    public function expunge(): void
    {
        $this->assertSelected();
        $tag = $this->nextTag();
        $this->stream->write($tag . " EXPUNGE\r\n");
        $result = $this->readUntilTagged($tag);
        if ($result['status'] !== 'OK') {
            throw new ImapException('Expunge failed: ' . $result['text'], ImapException::NO);
        }
    }

    /** UID EXPUNGE (RFC 4315) — expunge only specific UIDs, if supported; else falls back to full EXPUNGE. */
    public function uidExpunge(array $uids): void
    {
        $this->assertSelected();
        if ($uids === []) {
            return;
        }
        $tag = $this->nextTag();
        if ($this->supports('UIDPLUS')) {
            $this->stream->write($tag . ' UID EXPUNGE ' . $this->uidSet($uids) . "\r\n");
        } else {
            $this->stream->write($tag . " EXPUNGE\r\n");
        }
        $result = $this->readUntilTagged($tag);
        if ($result['status'] !== 'OK') {
            throw new ImapException('Expunge failed: ' . $result['text'], ImapException::NO);
        }
    }

    /** APPEND a raw RFC822 message to a mailbox (used to save Sent items). */
    public function append(string $rawMailbox, string $rfc822, array $flags = [], ?\DateTimeInterface $date = null): void
    {
        $tag = $this->nextTag();
        $flagPart = $flags !== [] ? '(' . implode(' ', $flags) . ') ' : '';
        $datePart = $date ? $this->quote($date->format('d-M-Y H:i:s O')) . ' ' : '';
        $this->stream->write($tag . ' APPEND ' . $this->quote($rawMailbox) . ' ' . $flagPart . $datePart . '{' . strlen($rfc822) . "}\r\n");

        $this->expectContinuation('Server did not accept APPEND literal');
        $this->stream->write($rfc822 . "\r\n");
        $result = $this->readUntilTagged($tag);
        if ($result['status'] !== 'OK') {
            throw new ImapException('Append failed: ' . $result['text'], ImapException::NO);
        }
    }

    public function noop(): void
    {
        $tag = $this->nextTag();
        $this->stream->write($tag . " NOOP\r\n");
        $this->readUntilTagged($tag);
    }

    public function logout(): void
    {
        try {
            $tag = $this->nextTag();
            $this->stream->write($tag . " LOGOUT\r\n");
            $this->readUntilTagged($tag);
        } catch (\Throwable) {
            // best effort
        } finally {
            $this->stream->close();
        }
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function nextTag(): string
    {
        return 'A' . str_pad((string) ++$this->tagCounter, 4, '0', STR_PAD_LEFT);
    }

    /** Collects every response line up to (and including) the tagged completion. */
    private function collectUntilTagged(string $tag): array
    {
        $lines = [];
        while (true) {
            $line = $this->tokenizer->nextResponse();
            $lines[] = $line;
            if (($line[0] ?? null) === $tag) {
                break;
            }
        }
        return $lines;
    }

    /** @return array{status:string, text:string} */
    private function readUntilTagged(string $tag): array
    {
        $lines = $this->collectUntilTagged($tag);
        $last = end($lines);
        $status = strtoupper((string) ($last[1] ?? ''));
        return ['status' => $status, 'text' => $this->joinRest($last, 2)];
    }

    private function assertOk(array $lines, string $tag, string $errorPrefix): void
    {
        $last = end($lines);
        if (($last[0] ?? null) !== $tag || strtoupper((string) ($last[1] ?? '')) !== 'OK') {
            $status = strtoupper((string) ($last[1] ?? ''));
            throw new ImapException($errorPrefix . ': ' . $this->joinRest($last, 2), $status === 'NO' ? ImapException::NO : ImapException::BAD);
        }
    }

    private function joinRest(array $tokens, int $from): string
    {
        $parts = [];
        foreach (array_slice($tokens, $from) as $t) {
            $parts[] = is_array($t) ? '(' . implode(' ', array_map('strval', $t)) . ')' : (string) ($t ?? 'NIL');
        }
        return trim(implode(' ', $parts));
    }

    private function assertSelected(): void
    {
        if ($this->selectedMailbox === null) {
            throw new ImapException('No mailbox is selected.', ImapException::BAD);
        }
    }

    private function uidSet(array $uids): string
    {
        $uids = array_values(array_unique(array_map('intval', $uids)));
        sort($uids);
        return implode(',', $uids);
    }

    private function quote(string $s): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }

    private function expectContinuation(string $errorPrefix = 'Server did not accept literal'): void
    {
        $cont = $this->stream->readLine();
        if (!str_starts_with($cont, '+')) {
            throw new ImapException($errorPrefix . ': ' . $cont, ImapException::NO);
        }
    }

    /**
     * Minimal modified UTF-7 decoder for IMAP mailbox names (RFC 3501 §5.1.3).
     * Most servers only need this for non-ASCII folder names.
     */
    private function utf7ImapDecode(string $s): string
    {
        if (!str_contains($s, '&')) {
            return $s;
        }
        $out = '';
        $len = strlen($s);
        $i = 0;
        while ($i < $len) {
            $c = $s[$i];
            if ($c !== '&') {
                $out .= $c;
                $i++;
                continue;
            }
            $j = $i + 1;
            while ($j < $len && $s[$j] !== '-') {
                $j++;
            }
            $chunk = substr($s, $i + 1, $j - $i - 1);
            if ($chunk === '') {
                $out .= '&';
            } else {
                $b64 = str_replace(',', '/', $chunk);
                $pad = strlen($b64) % 4;
                if ($pad) {
                    $b64 .= str_repeat('=', 4 - $pad);
                }
                $bin = base64_decode($b64, true);
                $out .= $this->utf16beToUtf8($bin ?: '');
            }
            $i = $j + 1;
        }
        return $out;
    }

    private function utf16beToUtf8(string $bin): string
    {
        $converted = @mb_convert_encoding($bin, 'UTF-8', 'UTF-16BE');
        return $converted !== false ? $converted : '';
    }
}
