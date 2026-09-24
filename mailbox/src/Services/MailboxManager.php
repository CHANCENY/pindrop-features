<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\mailbox\src\Services;

use Simp\Pindrop\Modules\mailbox\src\Imap\ImapClient;
use Simp\Pindrop\Modules\mailbox\src\Imap\ImapClientInterface;
use Simp\Pindrop\Modules\mailbox\src\Imap\ImapException;
use Simp\Pindrop\Modules\mailbox\src\Imap\Mime\MimeMessage;

/**
 * Ties the IMAP client, MIME parser, the mailbox_messages cache table, and
 * the CMS's outbound MailManager together into the operations the
 * controller needs. One instance per request; the IMAP connection is opened
 * lazily on first use and reused for the rest of the request.
 */
class MailboxManager
{
    private const HEADER_FETCH_ITEMS =
        'FLAGS INTERNALDATE RFC822.SIZE BODY.PEEK[HEADER.FIELDS (FROM TO CC SUBJECT DATE MESSAGE-ID IN-REPLY-TO)]';
    private const SPECIAL_USE_FLAGS = ['sent' => '\\Sent', 'drafts' => '\\Drafts', 'trash' => '\\Trash', 'junk' => '\\Junk', 'archive' => '\\Archive'];
    private const FLAG_MAP = ['seen' => '\\Seen', 'flagged' => '\\Flagged', 'answered' => '\\Answered'];

    private ?string $selectedFolder = null;
    private bool $selectedReadOnly = true;
    private ?array $folderListCache = null;
    private bool $connected = false;

    public function __construct(
        private readonly array $account,
        private readonly ImapClientInterface $client,
        private readonly \Closure $db, // fn(): object with ->table($name)
        private readonly ?\Closure $mailManagerFactory = null, // fn(): MailManager-like, injectable for tests
    ) {
    }

    public static function fromAccount(array $account): self
    {
        $client = new ImapClient($account['host'], (int) $account['port'], $account['encryption']);
        return new self(
            $account,
            $client,
            fn() => getAppContainer()->get('database'),
            fn() => getAppContainer()->get('mail.manager')
        );
    }

    private function ensureConnected(): void
    {
        if ($this->connected) {
            return;
        }
        try {
            $this->client->connect();
            $this->client->login($this->account['username'], $this->account['password']);
        } catch (ImapException $e) {
            throw new MailboxException('Could not connect to the mail server: ' . $e->getMessage(), 502, 'imap-' . $e->kind, $e);
        }
        $this->connected = true;
    }

    private function db()
    {
        return ($this->db)();
    }

    // ------------------------------------------------------------------
    // Folders
    // ------------------------------------------------------------------

    /** @return array<int, array{name:string, raw:string, delimiter:?string, role:?string, unread:?int}> */
    public function listFolders(): array
    {
        if ($this->folderListCache !== null) {
            return $this->folderListCache;
        }
        $this->ensureConnected();
        try {
            $raw = $this->client->listMailboxes();
        } catch (ImapException $e) {
            throw new MailboxException('Could not list folders: ' . $e->getMessage(), 502, 'imap-' . $e->kind, $e);
        }

        $roles = $this->resolveFolderRoles($raw);
        $out = [];
        foreach ($raw as $f) {
            if (!$f['selectable']) {
                continue;
            }
            $role = array_search($f['raw_name'], $roles, true) ?: null;
            $unread = $role !== null || $f['raw_name'] === $this->account['watchFolder']
                ? $this->db()->table('mailbox_messages')->where('folder', '=', $f['raw_name'])->where('is_seen', '=', 0)->count()
                : null;
            $out[] = ['name' => $f['name'], 'raw' => $f['raw_name'], 'delimiter' => $f['delimiter'], 'role' => $role, 'unread' => $unread];
        }
        return $this->folderListCache = $out;
    }

    /** Merges configured overrides with server-advertised SPECIAL-USE flags (RFC 6154). */
    private function resolveFolderRoles(array $rawFolders): array
    {
        $roles = [];
        foreach (self::SPECIAL_USE_FLAGS as $role => $flag) {
            foreach ($rawFolders as $f) {
                if (in_array($flag, $f['flags'], true)) {
                    $roles[$role] = $f['raw_name'];
                    break;
                }
            }
        }
        foreach ($this->account['folderRoles'] as $role => $raw) {
            if ($raw !== '') {
                $roles[$role] = $raw;
            }
        }
        // Heuristic fallback for servers with no SPECIAL-USE and no configured override.
        $names = array_column($rawFolders, 'raw_name');
        foreach (['sent' => ['Sent', 'Sent Items', 'Sent Mail'], 'drafts' => ['Drafts'], 'trash' => ['Trash', 'Deleted Items', 'Deleted Messages'], 'junk' => ['Junk', 'Spam']] as $role => $guesses) {
            if (isset($roles[$role])) {
                continue;
            }
            foreach ($guesses as $guess) {
                foreach ($names as $n) {
                    if (strcasecmp($n, $guess) === 0 || strcasecmp(ltrim((string) strrchr($n, '/'), '/'), $guess) === 0) {
                        $roles[$role] = $n;
                        continue 3;
                    }
                }
            }
        }
        return $roles;
    }

    public function trashFolder(): ?string
    {
        return $this->resolveFolderRoles($this->rawFolders())['trash'] ?? null;
    }

    public function sentFolder(): ?string
    {
        return $this->resolveFolderRoles($this->rawFolders())['sent'] ?? null;
    }

    private function rawFolders(): array
    {
        $this->ensureConnected();
        return $this->client->listMailboxes();
    }

    public function createFolder(string $rawName): void
    {
        $this->ensureConnected();
        try {
            $this->client->createMailbox($rawName);
        } catch (ImapException $e) {
            throw new MailboxException($e->getMessage(), 502, 'imap-' . $e->kind, $e);
        }
        $this->folderListCache = null;
    }

    public function renameFolder(string $rawOld, string $rawNew): void
    {
        $this->ensureConnected();
        try {
            $this->client->renameMailbox($rawOld, $rawNew);
        } catch (ImapException $e) {
            throw new MailboxException($e->getMessage(), 502, 'imap-' . $e->kind, $e);
        }
        $this->db()->table('mailbox_messages')->where('folder', '=', $rawOld)->update(['folder' => $rawNew]);
        $this->folderListCache = null;
    }

    public function deleteFolder(string $rawName): void
    {
        $this->ensureConnected();
        try {
            $this->client->deleteMailbox($rawName);
        } catch (ImapException $e) {
            throw new MailboxException($e->getMessage(), 502, 'imap-' . $e->kind, $e);
        }
        $this->db()->table('mailbox_messages')->where('folder', '=', $rawName)->delete();
        $this->folderListCache = null;
    }

    // ------------------------------------------------------------------
    // Selecting / syncing a folder into the cache
    // ------------------------------------------------------------------

    private function select(string $rawFolder, bool $readOnly): array
    {
        $this->ensureConnected();
        try {
            $info = $this->client->select($rawFolder, $readOnly);
        } catch (ImapException $e) {
            throw new MailboxException('Could not open folder "' . $rawFolder . '": ' . $e->getMessage(), 404, 'imap-' . $e->kind, $e);
        }
        $this->selectedFolder = $rawFolder;
        $this->selectedReadOnly = $readOnly;
        return $info;
    }

    /** Pulls any headers newer than what's cached, handling UIDVALIDITY resets. Returns SELECT info plus which UIDs were newly cached this call. */
    public function syncFolder(string $rawFolder, int $maxPerSync = 1000): array
    {
        $info = $this->select($rawFolder, true);
        $table = $this->db()->table('mailbox_messages');

        $cachedTop = $table->where('folder', '=', $rawFolder)->orderBy('uid', 'DESC')->limit(1)->first();
        if ($cachedTop !== null && (int) $cachedTop['uidvalidity'] !== $info['uidvalidity']) {
            // The server recreated this mailbox (UIDs are no longer meaningful) - drop the stale cache.
            $table->where('folder', '=', $rawFolder)->delete();
            $cachedTop = null;
        }
        $sinceUid = $cachedTop ? (int) $cachedTop['uid'] + 1 : 1;

        try {
            $uids = $this->client->uidSearch($sinceUid > 1 ? "UID {$sinceUid}:*" : 'ALL');
        } catch (ImapException $e) {
            throw new MailboxException('Could not search folder: ' . $e->getMessage(), 502, 'imap-' . $e->kind, $e);
        }
        // "UID x:*" always returns at least the highest UID even with nothing new; filter properly.
        $uids = array_values(array_filter($uids, fn($u) => $u >= $sinceUid));
        if (count($uids) > $maxPerSync) {
            $uids = array_slice($uids, -$maxPerSync);
        }

        foreach (array_chunk($uids, 200) as $chunk) {
            $this->fetchAndCacheHeaders($rawFolder, $chunk, $info['uidvalidity']);
        }

        // Also refresh flags for a small recent window, since STORE by another client isn't reflected otherwise.
        $this->refreshRecentFlags($rawFolder);

        return $info + ['newUids' => $uids];
    }

    private function fetchAndCacheHeaders(string $rawFolder, array $uids, int $uidValidity): void
    {
        if ($uids === []) {
            return;
        }
        try {
            $rows = $this->client->uidFetch($uids, self::HEADER_FETCH_ITEMS);
        } catch (ImapException $e) {
            throw new MailboxException('Could not fetch messages: ' . $e->getMessage(), 502, 'imap-' . $e->kind, $e);
        }
        $table = $this->db()->table('mailbox_messages');
        foreach ($rows as $uid => $row) {
            $headerText = $this->pickBracketValue($row, 'BODY[');
            $flags = array_map('strval', $row['FLAGS'] ?? []);
            $mime = MimeMessage::fromRaw((string) $headerText);
            $from = $mime->from()[0] ?? ['name' => null, 'email' => null];
            $table->upsert([
                'folder' => $rawFolder,
                'uid' => $uid,
                'uidvalidity' => $uidValidity,
                'message_id' => $mime->messageId(),
                'in_reply_to' => $mime->inReplyTo(),
                'subject' => mb_substr($mime->subject(), 0, 990),
                'from_name' => $from['name'] !== null ? mb_substr($from['name'], 0, 250) : null,
                'from_email' => ($from['email'] ?? '') !== '' ? $from['email'] : null,
                'to_json' => json_encode($mime->to()),
                'cc_json' => json_encode($mime->cc()),
                'message_date' => $mime->date()?->format('Y-m-d H:i:s'),
                'size_bytes' => (int) ($row['RFC822.SIZE'] ?? 0),
                'has_attachments' => 0, // unknown until the full body is fetched; refined in getMessage()
                'snippet' => '',
                'is_seen' => in_array('\\Seen', $flags, true) ? 1 : 0,
                'is_flagged' => in_array('\\Flagged', $flags, true) ? 1 : 0,
                'is_answered' => in_array('\\Answered', $flags, true) ? 1 : 0,
                'is_draft' => in_array('\\Draft', $flags, true) ? 1 : 0,
                'is_deleted' => in_array('\\Deleted', $flags, true) ? 1 : 0,
            ], ['is_seen', 'is_flagged', 'is_answered', 'is_draft', 'is_deleted', 'subject', 'from_name', 'from_email']);
        }
    }

    /** Cheaply keeps flags of the most recent N cached messages in sync with the server. */
    private function refreshRecentFlags(string $rawFolder, int $window = 100): void
    {
        $table = $this->db()->table('mailbox_messages');
        $recent = $table->where('folder', '=', $rawFolder)->orderBy('uid', 'DESC')->limit($window)->get();
        if ($recent === []) {
            return;
        }
        $uids = array_column($recent, 'uid');
        try {
            $rows = $this->client->uidFetch($uids, 'FLAGS');
        } catch (ImapException) {
            return; // best-effort
        }
        foreach ($rows as $uid => $row) {
            $flags = array_map('strval', $row['FLAGS'] ?? []);
            $table->where('folder', '=', $rawFolder)->where('uid', '=', $uid)->update([
                'is_seen' => in_array('\\Seen', $flags, true) ? 1 : 0,
                'is_flagged' => in_array('\\Flagged', $flags, true) ? 1 : 0,
                'is_answered' => in_array('\\Answered', $flags, true) ? 1 : 0,
                'is_deleted' => in_array('\\Deleted', $flags, true) ? 1 : 0,
            ]);
        }
    }

    private function pickBracketValue(array $row, string $prefix): string
    {
        foreach ($row as $k => $v) {
            if (is_string($k) && str_starts_with($k, $prefix) && is_string($v)) {
                return $v;
            }
        }
        return '';
    }

    // ------------------------------------------------------------------
    // Message listing / reading
    // ------------------------------------------------------------------

    public function listMessages(string $rawFolder, int $page, int $perPage, ?string $query = null, bool $unseenOnly = false): array
    {
        $info = $this->syncFolder($rawFolder);
        $table = $this->db()->table('mailbox_messages')->where('folder', '=', $rawFolder);
        if ($unseenOnly) {
            $table = $table->where('is_seen', '=', 0);
        }
        if ($query !== null && trim($query) !== '') {
            $like = '%' . trim($query) . '%';
            $table = $table->whereRaw('(subject LIKE ? OR from_name LIKE ? OR from_email LIKE ? OR snippet LIKE ?)', [$like, $like, $like, $like]);
        }
        $total = (clone $table)->count();
        $rows = $table->orderBy('message_date', 'DESC')->orderBy('uid', 'DESC')->forPage(max(1, $page), $perPage)->get();

        return [
            'folder' => $rawFolder,
            'info' => $info,
            'total' => $total,
            'page' => max(1, $page),
            'perPage' => $perPage,
            'items' => array_map([$this, 'rowToSummary'], $rows),
        ];
    }

    private function rowToSummary(array $row): array
    {
        return [
            'uid' => (int) $row['uid'],
            'subject' => $row['subject'] !== '' ? $row['subject'] : '(no subject)',
            'from' => ['name' => $row['from_name'], 'email' => $row['from_email']],
            'date' => $row['message_date'],
            'size' => (int) $row['size_bytes'],
            'snippet' => $row['snippet'],
            'hasAttachments' => (bool) $row['has_attachments'],
            'seen' => (bool) $row['is_seen'],
            'flagged' => (bool) $row['is_flagged'],
            'answered' => (bool) $row['is_answered'],
        ];
    }

    /** Full message: fetches+caches the raw body on first read, parses MIME, marks \Seen. */
    public function getMessage(string $rawFolder, int $uid, bool $markSeen = true): array
    {
        $table = $this->db()->table('mailbox_messages');
        $cached = $table->where('folder', '=', $rawFolder)->where('uid', '=', $uid)->first();
        if ($cached === null) {
            // Not cached yet (e.g. deep-linked before a sync ran) - sync then retry once.
            $this->syncFolder($rawFolder);
            $cached = $table->where('folder', '=', $rawFolder)->where('uid', '=', $uid)->first();
            if ($cached === null) {
                throw new MailboxException('Message not found.', 404, 'not-found');
            }
        }

        $raw = $cached['raw_cached'];
        if ($raw === null) {
            $this->select($rawFolder, false);
            try {
                $rows = $this->client->uidFetch([$uid], 'BODY.PEEK[]');
            } catch (ImapException $e) {
                throw new MailboxException('Could not fetch the message: ' . $e->getMessage(), 502, 'imap-' . $e->kind, $e);
            }
            $raw = $this->pickBracketValue($rows[$uid] ?? [], 'BODY[');
            if ($raw === '') {
                throw new MailboxException('Message not found on the server (it may have been deleted elsewhere).', 404, 'not-found');
            }
            $table->where('folder', '=', $rawFolder)->where('uid', '=', $uid)->update([
                'raw_cached' => $raw,
                'cached_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $mime = MimeMessage::fromRaw($raw);
        $attachments = $mime->attachments();
        if ((bool) $cached['has_attachments'] !== ($attachments !== [])) {
            $table->where('folder', '=', $rawFolder)->where('uid', '=', $uid)->update(['has_attachments' => $attachments !== [] ? 1 : 0]);
        }
        if ($cached['snippet'] === '') {
            $table->where('folder', '=', $rawFolder)->where('uid', '=', $uid)->update(['snippet' => mb_substr($mime->snippet(), 0, 490)]);
        }

        if ($markSeen && !$cached['is_seen']) {
            $this->setFlag($rawFolder, [$uid], 'seen', true, false);
        }

        $body = $mime->preferredBody();
        if ($body['type'] === 'html') {
            $body['content'] = $this->inlineCidImages($body['content'], $mime);
        }

        return [
            'uid' => $uid,
            'folder' => $rawFolder,
            'subject' => $mime->subject(),
            'from' => $mime->from()[0] ?? null,
            'to' => $mime->to(),
            'cc' => $mime->cc(),
            'date' => $mime->date()?->format(DATE_ATOM),
            'messageId' => $mime->messageId(),
            'inReplyTo' => $mime->inReplyTo(),
            'references' => $mime->references(),
            'body' => $body,
            'attachments' => array_values(array_map(
                fn($i, $p) => ['index' => $i, 'filename' => $p->filename() ?? ('attachment-' . ($i + 1)), 'size' => $p->size(), 'contentType' => $p->contentType],
                array_keys($attachments),
                $attachments
            )),
            'flags' => ['seen' => true, 'flagged' => (bool) $cached['is_flagged'], 'answered' => (bool) $cached['is_answered']],
        ];
    }

    private function inlineCidImages(string $html, MimeMessage $mime): string
    {
        $inline = $mime->inlineParts();
        if ($inline === []) {
            return $html;
        }
        return preg_replace_callback('/cid:([^"\'\s)]+)/i', function (array $m) use ($inline) {
            $part = $inline[$m[1]] ?? null;
            return $part === null ? $m[0] : ('data:' . $part->contentType . ';base64,' . base64_encode($part->decodedBody()));
        }, $html) ?? $html;
    }

    public function getAttachment(string $rawFolder, int $uid, int $index): array
    {
        $this->getMessage($rawFolder, $uid, false); // ensures raw_cached is populated
        $row = $this->db()->table('mailbox_messages')->where('folder', '=', $rawFolder)->where('uid', '=', $uid)->first();
        $mime = MimeMessage::fromRaw((string) $row['raw_cached']);
        $attachments = $mime->attachments();
        if (!isset($attachments[$index])) {
            throw new MailboxException('Attachment not found.', 404, 'not-found');
        }
        $part = $attachments[$index];
        return [
            'filename' => $part->filename() ?? ('attachment-' . ($index + 1)),
            'contentType' => $part->contentType,
            'data' => $part->decodedBody(),
        ];
    }

    // ------------------------------------------------------------------
    // Flags / move / copy / delete
    // ------------------------------------------------------------------

    public function setFlag(string $rawFolder, array $uids, string $flag, bool $on, bool $reselect = true): void
    {
        if (!isset(self::FLAG_MAP[$flag])) {
            throw new MailboxException('Unknown flag.', 400, 'bad-request');
        }
        if ($reselect) {
            $this->select($rawFolder, false);
        }
        try {
            $this->client->uidStore($uids, $on ? '+' : '-', [self::FLAG_MAP[$flag]]);
        } catch (ImapException $e) {
            throw new MailboxException('Could not update flags: ' . $e->getMessage(), 502, 'imap-' . $e->kind, $e);
        }
        $col = 'is_' . $flag;
        $this->db()->table('mailbox_messages')->where('folder', '=', $rawFolder)->whereIn('uid', $uids)->update([$col => $on ? 1 : 0]);
    }

    public function move(string $rawFolder, array $uids, string $destRaw): void
    {
        $this->select($rawFolder, false);
        try {
            if ($this->client->supports('MOVE')) {
                $this->client->uidMove($uids, $destRaw);
            } else {
                $this->client->uidMoveFallback($uids, $destRaw);
            }
        } catch (ImapException $e) {
            throw new MailboxException('Could not move: ' . $e->getMessage(), 502, 'imap-' . $e->kind, $e);
        }
        // The moved rows now belong to the destination folder with new UIDs we don't know yet;
        // simplest correct thing is to drop the cached rows and let the next listMessages() resync both folders.
        $this->db()->table('mailbox_messages')->where('folder', '=', $rawFolder)->whereIn('uid', $uids)->delete();
    }

    public function copy(string $rawFolder, array $uids, string $destRaw): void
    {
        $this->select($rawFolder, true);
        try {
            $this->client->uidCopy($uids, $destRaw);
        } catch (ImapException $e) {
            throw new MailboxException('Could not copy: ' . $e->getMessage(), 502, 'imap-' . $e->kind, $e);
        }
    }

    /** Soft-delete (move to Trash) unless already in Trash, in which case it's permanent. */
    public function delete(string $rawFolder, array $uids): array
    {
        $trash = $this->trashFolder();
        if ($trash !== null && strcasecmp($trash, $rawFolder) !== 0) {
            $this->move($rawFolder, $uids, $trash);
            return ['permanent' => false];
        }
        $this->select($rawFolder, false);
        try {
            $this->client->uidStore($uids, '+', ['\\Deleted']);
            $this->client->uidExpunge($uids);
        } catch (ImapException $e) {
            throw new MailboxException('Could not delete: ' . $e->getMessage(), 502, 'imap-' . $e->kind, $e);
        }
        $this->db()->table('mailbox_messages')->where('folder', '=', $rawFolder)->whereIn('uid', $uids)->delete();
        return ['permanent' => true];
    }

    public function emptyTrash(): int
    {
        $trash = $this->trashFolder();
        if ($trash === null) {
            throw new MailboxException('No Trash folder is configured or detected.', 400, 'no-trash');
        }
        $this->select($trash, false);
        $all = $this->db()->table('mailbox_messages')->where('folder', '=', $trash)->pluck('uid');
        try {
            $this->client->uidStore($all !== [] ? $all : [1], '+', ['\\Deleted']);
            $this->client->expunge();
        } catch (ImapException $e) {
            throw new MailboxException('Could not empty Trash: ' . $e->getMessage(), 502, 'imap-' . $e->kind, $e);
        }
        return $this->db()->table('mailbox_messages')->where('folder', '=', $trash)->delete();
    }

    // ------------------------------------------------------------------
    // Sending / replying / forwarding
    // ------------------------------------------------------------------

    /**
     * @param array{to:array,cc?:array,bcc?:array,subject:string,bodyHtml?:?string,bodyText?:string,
     *   inReplyTo?:string,references?:string[],attachments?:array} $input
     *
     * Drives the PHPMailer instance directly (via MailManager::getMailer())
     * rather than MailManager::send()'s convenience wrapper, because that
     * wrapper's $to parameter maps straight to PHPMailer::addAddress($to)
     * for a single bare email address - it can't express multiple "To"
     * recipients or a "Name <email>" display form in one call.
     */
    public function send(array $input): array
    {
        if ($this->mailManagerFactory === null) {
            throw new MailboxException('Outbound mail is not available in this context.', 500, 'no-mailer');
        }
        $mailer = ($this->mailManagerFactory)();

        $to = self::normalizeAddresses($input['to'] ?? []);
        if ($to === []) {
            throw new MailboxException('At least one recipient is required.', 400, 'bad-request');
        }
        $cc = self::normalizeAddresses($input['cc'] ?? []);
        $bcc = self::normalizeAddresses($input['bcc'] ?? []);

        $signature = trim((string) ($this->account['signature'] ?? ''));
        $bodyHtml = $input['bodyHtml'] ?? null;
        $bodyText = (string) ($input['bodyText'] ?? '');
        if ($signature !== '') {
            $bodyText = rtrim($bodyText) . "\n\n-- \n" . $signature;
            if ($bodyHtml !== null) {
                $bodyHtml = rtrim($bodyHtml) . '<br><br>-- <br>' . nl2br(htmlspecialchars($signature));
            }
        }

        if (($this->account['username'] ?? '') !== '' && filter_var($this->account['username'], FILTER_VALIDATE_EMAIL)) {
            $mailer->setFrom($this->account['username']);
        }

        $phpMailer = $mailer->getMailer();
        $phpMailer->clearAddresses();
        $phpMailer->clearAttachments();
        $phpMailer->clearCustomHeaders();
        try {
            foreach ($to as $addr) {
                $phpMailer->addAddress($addr['email'], $addr['name'] ?? '');
            }
            foreach ($cc as $addr) {
                $phpMailer->addCC($addr['email'], $addr['name'] ?? '');
            }
            foreach ($bcc as $addr) {
                $phpMailer->addBCC($addr['email'], $addr['name'] ?? '');
            }

            $phpMailer->Subject = (string) ($input['subject'] ?? '(no subject)');
            if ($bodyHtml !== null) {
                $phpMailer->msgHTML($bodyHtml);
                $phpMailer->AltBody = strip_tags($bodyHtml);
            } else {
                $phpMailer->isHTML(false);
                $phpMailer->Body = $bodyText;
            }

            if (!empty($input['inReplyTo'])) {
                $phpMailer->addCustomHeader('In-Reply-To', '<' . trim($input['inReplyTo'], '<> ') . '>');
            }
            if (!empty($input['references'])) {
                $phpMailer->addCustomHeader('References', implode(' ', array_map(fn($r) => '<' . trim($r, '<> ') . '>', $input['references'])));
            }
            foreach ((array) ($input['attachments'] ?? []) as $att) {
                if (is_string($att)) {
                    $phpMailer->addAttachment($att);
                } elseif (is_array($att) && isset($att['data'])) {
                    // Forwarded original attachments: raw bytes, no temp file needed.
                    $phpMailer->addStringAttachment($att['data'], $att['name'] ?? 'attachment', 'base64', $att['type'] ?? 'application/octet-stream');
                } elseif (is_array($att) && isset($att['path'])) {
                    // Freshly uploaded attachments: PHP's own temp upload path.
                    $phpMailer->addAttachment($att['path'], $att['name'] ?? basename($att['path']), $att['encoding'] ?? 'base64', $att['type'] ?? '');
                }
            }

            $ok = $phpMailer->send();
        } catch (\Throwable $e) {
            throw new MailboxException('Sending failed: ' . $e->getMessage(), 502, 'send-failed', $e);
        }

        if (!$ok) {
            throw new MailboxException('Sending failed: ' . ($phpMailer->ErrorInfo ?? $mailer->getLastError()), 502, 'send-failed');
        }

        $this->appendToSent($mailer);
        return ['sent' => true];
    }

    /** @return array{name:?string,email:string}[] */
    private static function normalizeAddresses(array $addresses): array
    {
        $out = [];
        foreach ($addresses as $a) {
            if (is_array($a) && ($a['email'] ?? '') !== '') {
                $out[] = ['name' => $a['name'] ?? null, 'email' => $a['email']];
            } elseif (is_string($a) && trim($a) !== '') {
                $out[] = ['name' => null, 'email' => trim($a)];
            }
        }
        return $out;
    }

    private function appendToSent(object $mailer): void
    {
        $sent = $this->sentFolder();
        if ($sent === null) {
            return;
        }
        try {
            $raw = method_exists($mailer, 'getMailer') ? $mailer->getMailer()->getSentMIMEMessage() : null;
            if (!is_string($raw) || $raw === '') {
                return;
            }
            $this->ensureConnected();
            $this->client->append($sent, $raw, ['\\Seen']);
            $this->db()->table('mailbox_messages')->where('folder', '=', $sent)->delete(); // force a resync so the new Sent item shows up
        } catch (\Throwable) {
            // Sending already succeeded; failing to file a copy in Sent shouldn't surface as an error to the user.
        }
    }

    public function __destruct()
    {
        if ($this->connected) {
            try {
                $this->client->logout();
            } catch (\Throwable) {
            }
        }
    }
}
