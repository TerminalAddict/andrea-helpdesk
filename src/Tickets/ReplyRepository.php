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

        // Attach attachments to each reply
        foreach ($replies as &$reply) {
            $reply['attachments'] = $this->db->fetchAll(
                "SELECT id, filename, mime_type, size_bytes, download_token, created_at
                 FROM attachments WHERE reply_id = ?",
                [$reply['id']]
            );
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
