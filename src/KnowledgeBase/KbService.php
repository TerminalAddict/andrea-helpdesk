<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\KnowledgeBase;

use Andrea\Helpdesk\Core\Database;
use Andrea\Helpdesk\Core\Sanitizer;

class KbService
{
    public function __construct(private KbRepository $repo = new KbRepository()) {}

    public function createFromTicket(int $ticketId, int $authorAgentId): array
    {
        $db     = Database::getInstance();
        $ticket = $db->fetch("SELECT * FROM tickets WHERE id = ?", [$ticketId]);
        if (!$ticket) throw new \InvalidArgumentException('Ticket not found');

        $firstReply = $db->fetch(
            "SELECT body_html FROM replies WHERE ticket_id = ? ORDER BY created_at ASC LIMIT 1",
            [$ticketId]
        );

        $id      = $this->repo->create([
            'title'           => $ticket['subject'],
            'body_html'       => Sanitizer::html($firstReply['body_html'] ?? '<p>No content</p>'),
            'author_agent_id' => $authorAgentId,
            'source_ticket_id'=> $ticketId,
            'is_published'    => 0,
        ]);

        return $this->repo->findById($id) ?? [];
    }

    public function create(array $data, int $authorAgentId): array
    {
        $data['author_agent_id'] = $authorAgentId;
        if (array_key_exists('body_html', $data)) {
            $data['body_html'] = Sanitizer::html((string)$data['body_html']);
        }
        $id = $this->repo->create($data);
        return $this->repo->findById($id) ?? [];
    }

    public function update(int $id, array $data): array
    {
        if (array_key_exists('body_html', $data)) {
            $data['body_html'] = Sanitizer::html((string)$data['body_html']);
        }
        $this->repo->update($id, $data);
        return $this->repo->findById($id) ?? [];
    }

    public function publish(int $id): bool
    {
        return $this->repo->update($id, ['is_published' => 1]);
    }

    public function unpublish(int $id): bool
    {
        return $this->repo->update($id, ['is_published' => 0]);
    }
}
