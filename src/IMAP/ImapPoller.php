<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\IMAP;

use Andrea\Helpdesk\Tickets\TicketService;
use Andrea\Helpdesk\Tickets\ReplyService;
use Andrea\Helpdesk\Tickets\AttachmentService;
use Andrea\Helpdesk\Tickets\TicketRepository;
use Andrea\Helpdesk\Customers\CustomerRepository;
use Andrea\Helpdesk\Notifications\NotificationService;
use Andrea\Helpdesk\Core\Database;

class ImapPoller
{
    private $connection = null;
    private string $logFile;
    private array $config;

    public function __construct(
        array $config,
        private MessageParser $parser,
        private ThreadMatcher $matcher
    ) {
        $this->config  = $config;
        $storagePath   = getenv('STORAGE_PATH') ?: '/tmp';
        $this->logFile = $storagePath . '/logs/imap.log';
    }

    public function connect(): bool
    {
        $config = $this->config;

        if (empty($config['host']) || empty($config['username'])) {
            $this->log('IMAP host or username not configured', 'ERROR');
            return false;
        }

        $encFlag = match(strtolower($config['encryption'] ?? 'ssl')) {
            'ssl'  => '/ssl',
            'tls'  => '/tls',
            default => '/notls',
        };

        $folder  = str_replace(['{', '}'], '', $config['folder'] ?? 'INBOX');
        $mailbox = "{{$config['host']}:{$config['port']}/imap{$encFlag}/novalidate-cert}{$folder}";

        $this->connection = @imap_open($mailbox, $config['username'], $config['password'], 0, 1);

        if (!$this->connection) {
            $error = imap_last_error();
            $this->log("Failed to connect: {$error}", 'ERROR');
            return false;
        }

        $count = imap_num_msg($this->connection);
        $this->log("Connected to {$config['host']} as {$config['username']}. {$count} messages in folder.");
        return true;
    }

    public function poll(): int
    {
        if (!$this->connection) return 0;

        $msgNums = imap_search($this->connection, 'UNSEEN');
        if (!$msgNums) {
            $this->log("No unseen messages.");
            return 0;
        }

        $processed = 0;
        foreach ($msgNums as $msgNum) {
            try {
                if ($this->processMessage($msgNum)) {
                    $processed++;
                }
            } catch (\Throwable $e) {
                $this->log("Error processing message {$msgNum}: " . $e->getMessage(), 'ERROR');
            }
        }

        $this->log("Processed {$processed} of " . count($msgNums) . " unseen messages.");
        return $processed;
    }

    public function processMessage(int $msgNum): bool
    {
        $parsed = $this->parser->parse($this->connection, $msgNum);
        $config = $this->config;

        $this->log("Processing: [{$parsed['message_id']}] {$parsed['subject']} from {$parsed['from_email']}");

        // Skip if no from address
        if (empty($parsed['from_email'])) {
            $this->log("Skipping: no from address");
            $this->markSeen($msgNum);
            return false;
        }

        // Find existing ticket
        $existingTicket = $this->matcher->findExistingTicket($parsed);

        // Skip auto-replies that would create a new ticket (loop prevention)
        // If they match an existing thread we still add the reply — no auto-response is sent for replies
        if ($parsed['is_auto_reply'] && !$existingTicket) {
            $this->log("Skipping auto-reply (loop prevention): {$parsed['subject']}");
            $this->markSeen($msgNum);
            return false;
        }

        if ($existingTicket) {
            $this->log("Matched existing ticket: {$existingTicket['ticket_number']}");

            // Find or create customer
            $customerRepo = new CustomerRepository();
            $customer     = $customerRepo->upsertByEmail($parsed['from_email'], $parsed['from_name']);

            // Add reply
            $replyService = new ReplyService();
            $reply        = $replyService->createCustomerReply(
                $existingTicket['id'],
                $customer['id'],
                $parsed['body_html'] ?: nl2br(htmlspecialchars($parsed['body_text'])),
                $parsed['body_text'],
                $parsed['message_id'],
                $parsed['in_reply_to']
            );

            // Save attachments
            $this->saveAttachments($existingTicket['id'], $reply['id'], $parsed['attachments']);

        } else {
            $this->log("Creating new ticket from email: {$parsed['subject']}");

            $ticketService = new TicketService();

            // Build CC list
            $ccEmails = [];
            foreach ($parsed['cc'] as $cc) {
                if ($cc['email'] !== $parsed['from_email']) {
                    $ccEmails[] = $cc;
                }
            }

            $result = $ticketService->createFromEmail([
                'from_email'  => $parsed['from_email'],
                'from_name'   => $parsed['from_name'],
                'subject'     => $parsed['subject'],
                'body_html'   => $parsed['body_html'] ?: nl2br(htmlspecialchars($parsed['body_text'])),
                'body_text'   => $parsed['body_text'],
                'message_id'  => $parsed['message_id'],
                'reply_to'    => $parsed['reply_to'] ?: $parsed['from_email'],
                'cc_emails'   => $ccEmails,
            ]);

            // Apply account tag if set
            if (!empty($config['tag_id'])) {
                $ticketRepo = new TicketRepository();
                $ticketRepo->addTag($result['ticket']['id'], (int)$config['tag_id']);
            }

            // Save attachments to the new ticket
            $db          = Database::getInstance();
            $firstReply  = $db->fetch(
                "SELECT id FROM replies WHERE ticket_id = ? ORDER BY id ASC LIMIT 1",
                [$result['ticket']['id']]
            );
            $this->saveAttachments(
                $result['ticket']['id'],
                $firstReply['id'] ?? null,
                $parsed['attachments']
            );
        }

        // Mark as seen
        $this->markSeen($msgNum);

        // Optionally delete
        if ($config['delete_after_import']) {
            imap_delete($this->connection, (string)$msgNum);
            imap_expunge($this->connection);
            $this->log("Deleted message {$msgNum}");
        }

        return true;
    }

    public function disconnect(): void
    {
        if ($this->connection) {
            imap_close($this->connection);
            $this->connection = null;
        }
    }

    private function markSeen(int $msgNum): void
    {
        imap_setflag_full($this->connection, (string)$msgNum, '\\Seen');
    }

    private function saveAttachments(int $ticketId, ?int $replyId, array $attachments): void
    {
        if (empty($attachments)) return;

        $service = new AttachmentService();
        foreach ($attachments as $attachment) {
            try {
                $service->storeRaw(
                    $ticketId,
                    $attachment['filename'],
                    $attachment['data'],
                    $attachment['mime_type'],
                    $replyId
                );
                $this->log("Saved attachment: {$attachment['filename']}");
            } catch (\Throwable $e) {
                $this->log("Failed to save attachment {$attachment['filename']}: " . $e->getMessage(), 'WARN');
            }
        }
    }

    private function log(string $message, string $level = 'INFO'): void
    {
        $line = '[' . date('Y-m-d H:i:s') . "] [{$level}] {$message}" . PHP_EOL;
        $dir  = dirname($this->logFile);
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
