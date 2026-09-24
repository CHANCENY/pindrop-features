<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\mailbox\src\Imap;

interface ImapClientInterface
{
    public function connect(): void;

    public function login(string $username, string $password): void;

    public function capability(): array;

    public function supports(string $capability): bool;

    /** @return array<int, array{name:string, raw_name:string, delimiter:?string, flags:string[], selectable:bool}> */
    public function listMailboxes(string $reference = '', string $pattern = '*'): array;

    public function createMailbox(string $rawName): void;

    public function deleteMailbox(string $rawName): void;

    public function renameMailbox(string $rawOld, string $rawNew): void;

    /** @return array{exists:int, recent:int, unseen:int, uidvalidity:int, uidnext:int, flags:string[], permanentFlags:string[]} */
    public function select(string $rawMailbox, bool $readOnly = false): array;

    public function uidSearch(string $criteria = 'ALL'): array;

    /** @return array<int, array<string, mixed>> */
    public function uidFetch(array $uids, string $items): array;

    public function uidStore(array $uids, string $mode, array $flags, bool $silent = true): void;

    public function uidCopy(array $uids, string $rawDestMailbox): void;

    public function uidMove(array $uids, string $rawDestMailbox): void;

    public function uidMoveFallback(array $uids, string $rawDestMailbox): void;

    public function expunge(): void;

    public function uidExpunge(array $uids): void;

    public function append(string $rawMailbox, string $rfc822, array $flags = [], ?\DateTimeInterface $date = null): void;

    public function noop(): void;

    public function logout(): void;
}
