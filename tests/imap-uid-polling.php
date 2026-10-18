<?php
declare(strict_types=1);

// Simulated mailbox: expunge renumbers sequence numbers, but never UIDs.
namespace Andrea\Helpdesk\IMAP {
    const SE_UID = 1;
    const FT_UID = 1;
    const FT_PEEK = 2;
    const ST_UID = 1;
    const TYPETEXT = 0;
    const TYPEMULTIPART = 1;
    const ENCBASE64 = 3;
    const ENCQUOTEDPRINTABLE = 4;

    class Mailbox
    {
        public array $messages = [101 => 'first', 205 => 'second', 309 => 'third', 412 => 'fourth'];
        public array $seen = [];
        public array $deleted = [];
        public array $fail = [];
    }
    function check(bool $ok, string $message): void
    {
        if (!$ok) throw new \RuntimeException($message);
    }
    function imap_search($box, $criteria, $flags = 0): array
    {
        check($flags === SE_UID, 'Search must return UIDs');
        return array_keys(array_diff_key($box->messages, $box->seen));
    }
    function imap_fetchbody($box, $uid, $part, $flags = 0): string|false
    {
        check($flags === (FT_UID | FT_PEEK), 'Fetch must use UID and PEEK');
        if (!isset($box->messages[$uid]) || ($box->fail[$uid] ?? '') === $part) return false;
        return $part === '0'
            ? "Subject: Receipt {$uid}\r\nMessage-ID: <{$uid}@example.test>\r\nAuto-Submitted: auto-generated\r\n"
            : $box->messages[$uid];
    }
    function imap_rfc822_parse_headers($raw): object
    {
        preg_match('/Subject: ([^\r\n]+)/', $raw, $match);
        return (object)[
            'subject' => $match[1],
            'from' => [(object)['mailbox' => 'billing', 'host' => 'example.test']],
        ];
    }
    function imap_utf8($value): string { return $value; }
    function imap_fetchstructure($box, $uid, $flags = 0): object|false
    {
        check($flags === FT_UID, 'Structure must use UID');
        if (($box->fail[$uid] ?? '') === 'structure') return false;
        return (object)['type' => TYPETEXT, 'subtype' => 'PLAIN', 'encoding' => 0];
    }
    function imap_setflag_full($box, $uid, $flag, $options = 0): bool
    {
        check($options === ST_UID, 'Seen flag must use UID');
        check(isset($box->messages[(int)$uid]), 'Cannot mark missing UID seen');
        $box->seen[(int)$uid] = true;
        return true;
    }
    function imap_delete($box, $uid, $flags = 0): bool
    {
        check($flags === FT_UID, 'Deletion must use UID');
        $box->deleted[] = (int)$uid;
        return true;
    }
    function imap_expunge($box): bool
    {
        foreach ($box->deleted as $uid) unset($box->messages[$uid]);
        return true;
    }
    class ThreadMatcher
    {
        public function findExistingTicket(array $parsed): ?array { return null; }
    }
    class IncomingEmailBlockService
    {
        public function isBlocked(string $email): bool { return false; }
    }
}

namespace Andrea\Helpdesk\Tickets {
    class TicketService
    {
        public static array $received = [];
        public function createFromEmail(array $data): array
        {
            self::$received[] = $data;
            return ['ticket' => ['id' => count(self::$received), 'ticket_number' => 'TEST']];
        }
    }
}
namespace Andrea\Helpdesk\Core {
    class Database
    {
        public static function getInstance(): self { return new self(); }
        public function fetch(...$args): array { return ['id' => 1]; }
    }
}

namespace {
    use Andrea\Helpdesk\IMAP\ImapPoller;
    use Andrea\Helpdesk\IMAP\MessageParser;
    use Andrea\Helpdesk\IMAP\Mailbox;
    use Andrea\Helpdesk\IMAP\ThreadMatcher;
    use Andrea\Helpdesk\Tickets\TicketService;
    use function Andrea\Helpdesk\IMAP\check;

    require_once __DIR__ . '/../src/IMAP/MessageParser.php';
    require_once __DIR__ . '/../src/IMAP/ImapPoller.php';

    $log = tempnam(sys_get_temp_dir(), 'imap-uid-test-');
    try {
        foreach ([true, false] as $delete) {
            foreach ([null, '0', 'structure', '1'] as $failure) {
                $box = new Mailbox();
                if ($failure !== null) $box->fail[205] = $failure;
                TicketService::$received = [];
                $poller = new ImapPoller(['delete_after_import' => $delete], new MessageParser(), new ThreadMatcher());
                (new ReflectionProperty($poller, 'connection'))->setValue($poller, $box);
                (new ReflectionProperty($poller, 'logFile'))->setValue($poller, $log);
                $expected = $failure === null ? 4 : 3;
                check($poller->poll() === $expected, 'Wrong processed count');
                $bodies = array_column(TicketService::$received, 'body_text');
                check($bodies === ($failure === null ? ['first', 'second', 'third', 'fourth'] : ['first', 'third', 'fourth']), 'Skipped or mixed-up messages');
                check(count($box->seen) === $expected, 'Wrong Seen count');
                check(count($box->deleted) === ($delete ? $expected : 0), 'Wrong deletion count');
                if ($failure !== null) {
                    check(isset($box->messages[205]) && !isset($box->seen[205]), 'Failed fetch must remain unread');
                    $box->fail = [];
                    check($poller->poll() === 1, 'Failed message must import on retry');
                }
                echo 'PASS: delete=' . (int)$delete . ', failure=' . ($failure ?? 'none') . "\n";
            }
        }
    } finally {
        unlink($log);
    }
}
