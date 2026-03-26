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

    public function createAgentReply(int $ticketId, int $agentId, string $bodyHtml, bool $isPrivate = false, array $ccEmails = [], array $attachmentIds = [], bool $includeSignature = true): array
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
                $notificationService->onAgentReply($ticket, $reply, $agent, $customer, $ccEmails, $attachmentIds, $includeSignature);
            }
        }

        // Notify any @mentioned agents
        $this->notifyMentions($bodyHtml, $agentId, $ticket);

        return $reply ?? [];
    }

    private function notifyMentions(string $bodyHtml, int $authorAgentId, array $ticket): void
    {
        if (!str_contains($bodyHtml, 'mention-')) return;

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<!DOCTYPE html><html><body>' . $bodyHtml . '</body></html>');
        libxml_clear_errors();

        $mentionedIds = [];
        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//*[contains(@class,"mention-")]') as $node) {
            foreach (explode(' ', $node->getAttribute('class')) as $cls) {
                if (preg_match('/^mention-(\d+)$/', $cls, $m)) {
                    $mentionedIds[] = (int)$m[1];
                }
            }
        }

        $mentionedIds = array_unique($mentionedIds);
        if (!$mentionedIds) return;

        $notificationService = new NotificationService();
        foreach ($mentionedIds as $mentionedAgentId) {
            if ($mentionedAgentId === $authorAgentId) continue; // don't notify self
            $notificationService->onAgentMentioned($ticket, $mentionedAgentId, $authorAgentId);
        }
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

            // Auto-set status to waiting_for_reply on customer reply (always, even if closed/resolved)
            $this->db->execute("UPDATE tickets SET status = 'waiting_for_reply' WHERE id = ?", [$ticketId]);

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
