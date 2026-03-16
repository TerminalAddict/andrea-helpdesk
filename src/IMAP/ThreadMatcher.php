<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\IMAP;

use Andrea\Helpdesk\Core\Database;

class ThreadMatcher
{
    public function __construct(private Database $db) {}

    /**
     * Find an existing ticket for this inbound message.
     * Returns the full ticket row or null if this is a new ticket.
     */
    public function findExistingTicket(array $parsedMessage): ?array
    {
        // 1. Check In-Reply-To header against known message IDs
        if (!empty($parsedMessage['in_reply_to'])) {
            $ticket = $this->searchByMessageId($parsedMessage['in_reply_to']);
            if ($ticket) return $ticket;
        }

        // 2. Check References header (any message ID in the chain)
        if (!empty($parsedMessage['references'])) {
            $refs = preg_split('/\s+/', trim($parsedMessage['references']));
            foreach ($refs as $ref) {
                $ref    = trim($ref, '<> ');
                $ticket = $this->searchByMessageId($ref);
                if ($ticket) return $ticket;
            }
        }

        // 3. Check X-Ticket-ID custom header
        if (!empty($parsedMessage['x_ticket_id'])) {
            $ticket = $this->searchBySubjectTicketNumber(trim($parsedMessage['x_ticket_id']));
            if ($ticket) return $ticket;
        }

        // 4. Extract ticket number from Subject like "Re: Something [HD-2026-03-17-0001]"
        $ticketNumber = $this->extractTicketNumber($parsedMessage['subject'] ?? '');
        if ($ticketNumber) {
            $ticket = $this->searchBySubjectTicketNumber($ticketNumber);
            if ($ticket) return $ticket;
        }

        return null;
    }

    public function extractTicketNumber(string $subject): ?string
    {
        // Match patterns like [HD-2026-03-17-0001] or HD-2026-03-17-0001
        if (preg_match('/\[([A-Z]+-\d{4}-\d{2}-\d{2}-\d+)\]/i', $subject, $m)) {
            return strtoupper($m[1]);
        }
        if (preg_match('/\b([A-Z]+-\d{4}-\d{2}-\d{2}-\d+)\b/i', $subject, $m)) {
            return strtoupper($m[1]);
        }
        return null;
    }

    private function searchByMessageId(string $messageId): ?array
    {
        $messageId = trim($messageId, '<> ');
        if (!$messageId) return null;

        // Check replies table first (most recent match)
        $reply = $this->db->fetch(
            "SELECT ticket_id FROM replies WHERE raw_message_id = ?",
            [$messageId]
        );
        if ($reply) {
            return $this->db->fetch(
                "SELECT * FROM tickets WHERE id = ? AND deleted_at IS NULL AND merged_into_id IS NULL",
                [$reply['ticket_id']]
            );
        }

        // Check tickets table
        return $this->db->fetch(
            "SELECT * FROM tickets
             WHERE (original_message_id = ? OR last_message_id = ?)
               AND deleted_at IS NULL AND merged_into_id IS NULL",
            [$messageId, $messageId]
        );
    }

    private function searchBySubjectTicketNumber(string $ticketNumber): ?array
    {
        $ticket = $this->db->fetch(
            "SELECT * FROM tickets WHERE ticket_number = ? AND deleted_at IS NULL",
            [strtoupper($ticketNumber)]
        );

        // If merged, follow to target
        if ($ticket && $ticket['merged_into_id']) {
            return $this->db->fetch(
                "SELECT * FROM tickets WHERE id = ? AND deleted_at IS NULL",
                [$ticket['merged_into_id']]
            );
        }

        return $ticket;
    }
}
