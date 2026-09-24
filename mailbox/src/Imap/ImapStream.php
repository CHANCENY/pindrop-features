<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\mailbox\src\Imap;

/**
 * Raw byte/line I/O over a TCP or TLS socket.
 *
 * IMAP responses are *mostly* line-oriented (CRLF-terminated) but any field
 * can instead be a "literal": {N}\r\n followed by exactly N raw bytes, which
 * may themselves contain CR/LF. So reading IMAP is not simply "read lines" —
 * whoever parses a response must be able to switch, mid-line, to "read exactly
 * N bytes verbatim". This class provides those two primitives; ImapTokenizer
 * is what decides when to use which.
 */
class ImapStream
{
    /** @var resource */
    private $socket;

    public function __construct(
        string $host,
        int $port,
        string $encryption, // 'ssl' | 'tls' | 'none'
        private readonly float $timeout = 20.0
    ) {
        $errno = 0;
        $errstr = '';
        $scheme = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'SNI_enabled' => true,
            ],
        ]);
        $socket = @stream_socket_client(
            $scheme . $host . ':' . $port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($socket === false) {
            throw new ImapException("Could not connect to {$host}:{$port} ({$errstr})", ImapException::CONNECT);
        }
        stream_set_timeout($socket, (int) $timeout);
        $this->socket = $socket;

        if ($encryption === 'tls') {
            $this->startTls();
        }
    }

    public function startTls(): void
    {
        $ok = @stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($ok !== true) {
            throw new ImapException('TLS negotiation failed.', ImapException::CONNECT);
        }
    }

    public function write(string $data): void
    {
        $len = strlen($data);
        $written = 0;
        while ($written < $len) {
            $n = @fwrite($this->socket, substr($data, $written));
            if ($n === false || $n === 0) {
                $this->assertNotTimedOut();
                throw new ImapException('Connection closed while writing.', ImapException::IO);
            }
            $written += $n;
        }
    }

    /** Reads one CRLF-terminated line, WITHOUT the trailing CRLF. */
    public function readLine(): string
    {
        $line = '';
        while (true) {
            if (feof($this->socket)) {
                throw new ImapException('Connection closed by server.', ImapException::IO);
            }
            $chunk = @fgets($this->socket, 8192);
            if ($chunk === false) {
                $this->assertNotTimedOut();
                throw new ImapException('Connection closed while reading.', ImapException::IO);
            }
            $line .= $chunk;
            if (str_ends_with($line, "\n")) {
                return rtrim($line, "\r\n");
            }
        }
    }

    /** Reads exactly $n raw bytes (a literal's payload). */
    public function readBytes(int $n): string
    {
        if ($n === 0) {
            return '';
        }
        $data = '';
        $remaining = $n;
        while ($remaining > 0) {
            if (feof($this->socket)) {
                throw new ImapException('Connection closed while reading a literal.', ImapException::IO);
            }
            $chunk = @fread($this->socket, min(65536, $remaining));
            if ($chunk === false || $chunk === '') {
                $this->assertNotTimedOut();
                throw new ImapException('Connection closed while reading a literal.', ImapException::IO);
            }
            $data .= $chunk;
            $remaining -= strlen($chunk);
        }
        return $data;
    }

    private function assertNotTimedOut(): void
    {
        $meta = @stream_get_meta_data($this->socket);
        if (!empty($meta['timed_out'])) {
            throw new ImapException('Connection timed out.', ImapException::TIMEOUT);
        }
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
