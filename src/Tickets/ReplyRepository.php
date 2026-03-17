<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Tickets;

use Andrea\Helpdesk\Core\Database;

class ReplyRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function findByTicketId(int $ticketId, bool $includePrivate = true): array
    {
        $privateClause = $includePrivate ? '' : 'AND r.is_private = 0';
        $replies = $this->db->fetchAll(
            "SELECT r.*,
                    a.name AS agent_name, a.email AS agent_email,
                    c.name AS customer_name, c.email AS customer_email
             FROM replies r
             LEFT JOIN agents a ON a.id = r.agent_id
             LEFT JOIN customers c ON c.id = r.customer_id
             WHERE r.ticket_id = ? {$privateClause}
             ORDER BY r.created_at ASC",
            [$ticketId]
        );

        // Batch load all attachments for these replies in one query
        $attachmentsByReply = [];
        if (!empty($replies)) {
            $replyIds     = array_column($replies, 'id');
            $placeholders = implode(',', array_fill(0, count($replyIds), '?'));
            $allAttachments = $this->db->fetchAll(
                "SELECT id, reply_id, filename, mime_type, size_bytes, download_token, created_at
                 FROM attachments WHERE reply_id IN ({$placeholders})",
                $replyIds
            );
            foreach ($allAttachments as $att) {
                $attachmentsByReply[(int)$att['reply_id']][] = $att;
            }
        }

        foreach ($replies as &$reply) {
            $reply['attachments'] = $attachmentsByReply[(int)$reply['id']] ?? [];

            // Computed fields for the frontend
            if ($reply['author_type'] === 'system') {
                $reply['type'] = 'system';
            } elseif (!empty($reply['is_private'])) {
                $reply['type'] = 'internal';
            } else {
                $reply['type'] = 'reply';
            }

            $reply['author_name'] = $reply['agent_name'] ?? $reply['customer_name'] ?? 'Unknown';
            $reply['body']        = strip_tags($reply['body_html'] ?? $reply['body_text'] ?? '');
        }

        return $replies;
    }

    public function findByMessageId(string $messageId): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM replies WHERE raw_message_id = ?",
            [$messageId]
        );
    }

    public function create(array $data): int
    {
        return $this->db->insert(
            "INSERT INTO replies (ticket_id, author_type, agent_id, customer_id, body_html, body_text,
             is_private, direction, raw_message_id, in_reply_to, email_sent_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $data['ticket_id'],
                $data['author_type'],
                $data['agent_id'] ?? null,
                $data['customer_id'] ?? null,
                $data['body_html'],
                $data['body_text'] ?? null,
                $data['is_private'] ?? 0,
                $data['direction'],
                $data['raw_message_id'] ?? null,
                $data['in_reply_to'] ?? null,
                $data['email_sent_at'] ?? null,
            ]
        );
    }

    public function update(int $id, array $data): bool
    {
        $allowed = ['body_html', 'body_text', 'email_sent_at', 'raw_message_id'];
        $set     = [];
        $params  = [];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $set[]    = "{$col} = ?";
                $params[] = $data[$col];
            }
        }

        if (empty($set)) return false;
        $params[] = $id;
        return $this->db->execute("UPDATE replies SET " . implode(', ', $set) . " WHERE id = ?", $params);
    }

    public function findById(int $id): ?array
    {
        return $this->db->fetch("SELECT * FROM replies WHERE id = ?", [$id]);
    }
}
