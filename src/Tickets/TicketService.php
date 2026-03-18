<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Tickets;

use Andrea\Helpdesk\Core\Database;
use Andrea\Helpdesk\Customers\CustomerRepository;
use Andrea\Helpdesk\Notifications\NotificationService;
use Andrea\Helpdesk\Settings\SettingsService;
use Andrea\Helpdesk\KnowledgeBase\KbService;

class TicketService
{
    private Database $db;
    private TicketRepository $ticketRepo;
    private CustomerRepository $customerRepo;
    private ReplyRepository $replyRepo;

    public function __construct()
    {
        $this->db           = Database::getInstance();
        $this->ticketRepo   = new TicketRepository();
        $this->customerRepo = new CustomerRepository();
        $this->replyRepo    = new ReplyRepository();
    }

    /**
     * Create a ticket from an inbound email.
     */
    public function createFromEmail(array $emailData): array
    {
        $this->db->beginTransaction();
        try {
            $customer = $this->customerRepo->upsertByEmail(
                $emailData['from_email'],
                $emailData['from_name'] ?? '',
            );

            $ticketNumber = $this->generateNumber();

            $ticketId = $this->ticketRepo->create([
                'ticket_number'      => $ticketNumber,
                'subject'            => $emailData['subject'],
                'channel'            => 'email',
                'customer_id'        => $customer['id'],
                'original_message_id'=> $emailData['message_id'] ?? null,
                'last_message_id'    => $emailData['message_id'] ?? null,
                'reply_to_address'   => $emailData['reply_to'] ?? $emailData['from_email'],
            ]);

            $ticket = $this->ticketRepo->findById($ticketId);

            // Create initial reply
            $this->replyRepo->create([
                'ticket_id'      => $ticketId,
                'author_type'    => 'customer',
                'customer_id'    => $customer['id'],
                'body_html'      => $emailData['body_html'] ?? nl2br(htmlspecialchars($emailData['body_text'] ?? '')),
                'body_text'      => $emailData['body_text'] ?? '',
                'is_private'     => 0,
                'direction'      => 'inbound',
                'raw_message_id' => $emailData['message_id'] ?? null,
            ]);

            // Handle CC participants
            foreach ($emailData['cc_emails'] ?? [] as $cc) {
                $ccEmail = is_array($cc) ? $cc['email'] : $cc;
                $ccName  = is_array($cc) ? ($cc['name'] ?? '') : '';
                if ($ccEmail && $ccEmail !== $emailData['from_email']) {
                    $ccCustomer = $this->customerRepo->upsertByEmail($ccEmail, $ccName);
                    $this->ticketRepo->addParticipant($ticketId, $ccEmail, $ccName, 'cc', $ccCustomer['id']);
                }
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        // Notify outside the transaction
        try {
            $notifications = new NotificationService();
            $notifications->onNewTicket($ticket, $customer);
        } catch (\Throwable) {}

        return ['ticket' => $ticket, 'customer' => $customer];
    }

    /**
     * Create a ticket on behalf of a customer (phone channel).
     */
    public function createFromAgent(array $data, int $agentId): array
    {
        $ticketNumber = $this->generateNumber();

        $ticketId = $this->ticketRepo->create([
            'ticket_number'     => $ticketNumber,
            'subject'           => $data['subject'],
            'channel'           => $data['channel'] ?? 'phone',
            'customer_id'       => $data['customer_id'],
            'priority'          => $data['priority'] ?? 'normal',
            'assigned_agent_id' => $data['assigned_agent_id'] ?? $agentId,
            'parent_ticket_id'  => $data['parent_ticket_id'] ?? null,
        ]);

        $ticket = $this->ticketRepo->findById($ticketId);

        // Create initial message
        if (!empty($data['body_html'])) {
            $this->replyRepo->create([
                'ticket_id'   => $ticketId,
                'author_type' => 'agent',
                'agent_id'    => $agentId,
                'body_html'   => $data['body_html'],
                'body_text'   => strip_tags($data['body_html']),
                'is_private'  => 0,
                'direction'   => 'outbound',
            ]);

            $this->db->execute(
                "UPDATE tickets SET first_response_at = NOW() WHERE id = ?",
                [$ticketId]
            );
        }

        // Notify customer and agents
        try {
            $customer = $this->customerRepo->findById($data['customer_id']);
            if ($customer) {
                $notifications = new NotificationService();
                $notifications->onNewTicket($ticket, $customer);
            }
        } catch (\Throwable) {}

        return ['ticket' => $ticket];
    }

    public function createChildTicket(int $parentId, array $data, int $agentId): array
    {
        $parent = $this->ticketRepo->findById($parentId);
        if (!$parent) throw new \InvalidArgumentException('Parent ticket not found');

        $data['parent_ticket_id'] = $parentId;
        $data['customer_id']      = $data['customer_id'] ?? $parent['customer_id'];

        $result = $this->createFromAgent($data, $agentId);

        // Add system note to parent
        $replyService = new ReplyService();
        $childNumber = $result['ticket']['ticket_number'];
        $replyService->createSystemReply($parentId, "Sub-ticket {$childNumber} created.", $agentId);

        return $result;
    }

    public function mergeTickets(int $sourceId, int $targetId, int $agentId): bool
    {
        $source = $this->ticketRepo->findById($sourceId);
        $target = $this->ticketRepo->findById($targetId);

        if (!$source || !$target) return false;

        $this->db->beginTransaction();
        try {
            // Move source replies to target
            $this->db->execute("UPDATE replies SET ticket_id = ? WHERE ticket_id = ?", [$targetId, $sourceId]);

            // Move attachments
            $this->db->execute("UPDATE attachments SET ticket_id = ? WHERE ticket_id = ?", [$targetId, $sourceId]);

            // Move participants (skip duplicates)
            $this->db->execute(
                "INSERT IGNORE INTO ticket_participants (ticket_id, customer_id, email, name)
                 SELECT ?, customer_id, email, name FROM ticket_participants WHERE ticket_id = ?",
                [$targetId, $sourceId]
            );
            $this->db->execute("DELETE FROM ticket_participants WHERE ticket_id = ?", [$sourceId]);

            // Move tags (skip duplicates)
            $this->db->execute(
                "INSERT IGNORE INTO ticket_tag_map (ticket_id, tag_id)
                 SELECT ?, tag_id FROM ticket_tag_map WHERE ticket_id = ?",
                [$targetId, $sourceId]
            );
            $this->db->execute("DELETE FROM ticket_tag_map WHERE ticket_id = ?", [$sourceId]);

            // Close source ticket
            $this->ticketRepo->update($sourceId, [
                'status'        => 'closed',
                'merged_into_id'=> $targetId,
                'closed_at'     => date('Y-m-d H:i:s'),
            ]);

            // Add system note to target
            $replyService = new ReplyService();
            $replyService->createSystemReply($targetId, "Ticket {$source['ticket_number']} was merged into this ticket.", $agentId);
            $replyService->createSystemReply($sourceId, "This ticket was merged into {$target['ticket_number']}.", $agentId);

            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function moveToKnowledgeBase(int $ticketId, int $agentId): array
    {
        $ticket = $this->ticketRepo->findById($ticketId);
        if (!$ticket) throw new \InvalidArgumentException('Ticket not found');

        $kbService = new KbService();
        return $kbService->createFromTicket($ticketId, $agentId);
    }

    public function updateStatus(int $ticketId, string $status, int $agentId): bool
    {
        $validStatuses = ['open', 'pending', 'resolved', 'closed'];
        if (!in_array($status, $validStatuses, true)) {
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }

        $updates = ['status' => $status];
        if (in_array($status, ['resolved', 'closed'], true)) {
            $updates['closed_at'] = date('Y-m-d H:i:s');
        }

        $replyService = new ReplyService();
        $replyService->createSystemReply($ticketId, "Status changed to {$status}.", $agentId);

        return $this->ticketRepo->update($ticketId, $updates);
    }

    public function generateNumber(): string
    {
        $settings = SettingsService::getInstance();
        $prefix   = $settings->getTicketPrefix();
        $date     = date('Y-m-d');
        return $this->ticketRepo->generateTicketNumber($prefix, $date);
    }

    public function getWithFullThread(int $ticketId): array
    {
        $ticket = $this->ticketRepo->findById($ticketId);
        if (!$ticket) return [];

        $replyRepo   = new ReplyRepository();
        $attachSvc   = new AttachmentService();

        $ticket['replies']      = $replyRepo->findByTicketId($ticketId, true);
        $ticket['attachments']  = $attachSvc->getAttachmentsForTicket($ticketId);
        $ticket['participants'] = $this->ticketRepo->getParticipants($ticketId);
        $ticket['relations']    = $this->ticketRepo->getRelations($ticketId);
        $ticket['tags']         = $this->ticketRepo->getTags($ticketId);
        $ticket['children']     = $this->ticketRepo->getChildTickets($ticketId);

        if (!empty($ticket['parent_ticket_id'])) {
            $ticket['parent'] = $this->ticketRepo->findById((int)$ticket['parent_ticket_id']);
        }

        return $ticket;
    }
}
