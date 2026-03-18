<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Tickets;

use Andrea\Helpdesk\Core\Database;
use Andrea\Helpdesk\Notifications\NotificationService;

class ReplyService
{
    private Database $db;
    private ReplyRepository $replyRepo;

    public function __construct()
    {
        $this->db        = Database::getInstance();
        $this->replyRepo = new ReplyRepository();
    }

    public function createAgentReply(int $ticketId, int $agentId, string $bodyHtml, bool $isPrivate = false, array $ccEmails = [], array $attachmentIds = []): array
    {
        $ticket = $this->db->fetch("SELECT * FROM tickets WHERE id = ? AND deleted_at IS NULL", [$ticketId]);
        if (!$ticket) throw new \InvalidArgumentException('Ticket not found');

        $agent = $this->db->fetch("SELECT * FROM agents WHERE id = ?", [$agentId]);
        if (!$agent) throw new \InvalidArgumentException('Agent not found');

        $replyId = $this->replyRepo->create([
            'ticket_id'   => $ticketId,
            'author_type' => 'agent',
            'agent_id'    => $agentId,
            'body_html'   => $bodyHtml,
            'body_text'   => strip_tags($bodyHtml),
            'is_private'  => $isPrivate ? 1 : 0,
            'direction'   => 'outbound',
        ]);

        // Set first_response_at if this is the first agent reply
        if (!$ticket['first_response_at']) {
            $this->db->execute(
                "UPDATE tickets SET first_response_at = NOW() WHERE id = ? AND first_response_at IS NULL",
                [$ticketId]
            );
        }

        $reply = $this->replyRepo->findById($replyId);

        // Send email to customer if not a private note
        if (!$isPrivate) {
            $customer = $this->db->fetch("SELECT * FROM customers WHERE id = ?", [$ticket['customer_id']]);
            if ($customer) {
                $notificationService = new NotificationService();
                $notificationService->onAgentReply($ticket, $reply, $agent, $customer, $ccEmails, $attachmentIds);
            }
        }

        return $reply ?? [];
    }

    public function createCustomerReply(int $ticketId, int $customerId, string $bodyHtml, string $bodyText = '', string $rawMessageId = '', string $inReplyTo = ''): array
    {
        $ticket = $this->db->fetch("SELECT * FROM tickets WHERE id = ? AND deleted_at IS NULL", [$ticketId]);
        if (!$ticket) throw new \InvalidArgumentException('Ticket not found');

        $this->db->beginTransaction();
        try {
            $replyId = $this->replyRepo->create([
                'ticket_id'      => $ticketId,
                'author_type'    => 'customer',
                'customer_id'    => $customerId,
                'body_html'      => $bodyHtml,
                'body_text'      => $bodyText ?: strip_tags($bodyHtml),
                'is_private'     => 0,
                'direction'      => 'inbound',
                'raw_message_id' => $rawMessageId ?: null,
                'in_reply_to'    => $inReplyTo ?: null,
            ]);

            // Reopen ticket if resolved/pending
            if (in_array($ticket['status'], ['pending', 'resolved'], true)) {
                $this->db->execute("UPDATE tickets SET status = 'open' WHERE id = ?", [$ticketId]);
            }

            // Update last_message_id
            if ($rawMessageId) {
                $this->db->execute("UPDATE tickets SET last_message_id = ? WHERE id = ?", [$rawMessageId, $ticketId]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        $reply    = $this->replyRepo->findById($replyId);
        $customer = $this->db->fetch("SELECT * FROM customers WHERE id = ?", [$customerId]);

        if ($customer && $reply) {
            $notificationService = new NotificationService();
            $notificationService->onCustomerReply($ticket, $reply, $customer);
        }

        return $reply ?? [];
    }

    public function createSystemReply(int $ticketId, string $body, ?int $agentId = null): array
    {
        $replyId = $this->replyRepo->create([
            'ticket_id'   => $ticketId,
            'author_type' => 'system',
            'agent_id'    => $agentId,
            'body_html'   => '<p><em>' . htmlspecialchars($body) . '</em></p>',
            'body_text'   => $body,
            'is_private'  => 0,
            'direction'   => 'outbound',
        ]);

        return $this->replyRepo->findById($replyId) ?? [];
    }
}
