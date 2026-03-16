<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\KnowledgeBase;

use Andrea\Helpdesk\Core\Database;

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
            'body_html'       => $firstReply ? $firstReply['body_html'] : '<p>No content</p>',
            'author_agent_id' => $authorAgentId,
            'source_ticket_id'=> $ticketId,
            'is_published'    => 0,
        ]);

        return $this->repo->findById($id) ?? [];
    }

    public function create(array $data, int $authorAgentId): array
    {
        $data['author_agent_id'] = $authorAgentId;
        $id = $this->repo->create($data);
        return $this->repo->findById($id) ?? [];
    }

    public function update(int $id, array $data): array
    {
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
