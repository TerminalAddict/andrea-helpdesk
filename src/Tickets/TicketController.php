<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Tickets;

use Andrea\Helpdesk\Core\Database;
use Andrea\Helpdesk\Core\Request;
use Andrea\Helpdesk\Core\Response;
use Andrea\Helpdesk\Core\Exceptions\NotFoundException;
use Andrea\Helpdesk\Core\Exceptions\HttpException;
use Andrea\Helpdesk\Notifications\NotificationService;

class TicketController
{
    private TicketRepository $repo;
    private TicketService $service;
    private Database $db;

    public function __construct()
    {
        $this->repo    = new TicketRepository();
        $this->service = new TicketService();
        $this->db      = Database::getInstance();
    }

    public function index(Request $request): void
    {
        $page    = max(1, (int)$request->input('page', 1));
        $perPage = min(100, max(1, (int)$request->input('per_page', 25)));

        $filters = array_filter([
            'status'           => $request->input('status'),
            'priority'         => $request->input('priority'),
            'assigned_agent_id'=> $request->input('assigned_to'),
            'customer_id'      => $request->input('customer_id'),
            'channel'          => $request->input('channel'),
            'q'                => $request->input('q'),
            'from_date'        => $request->input('from'),
            'to_date'          => $request->input('to'),
            'tag_id'           => $request->input('tag_id'),
            'sort'             => $request->input('sort', 'updated_at'),
            'dir'              => $request->input('dir', 'desc'),
        ]);

        $result = $this->repo->findAll($filters, $page, $perPage);
        Response::paginated($result['items'], $result['total'], $page, $perPage);
    }

    public function store(Request $request): void
    {
        $request->validate([
            'customer_email' => 'required|email',
            'subject'        => 'required|max:255',
        ]);

        // Upsert customer by email
        $customerRepo = new \Andrea\Helpdesk\Customers\CustomerRepository();
        $customer     = $customerRepo->upsertByEmail(
            $request->input('customer_email'),
            $request->input('customer_name', '')
        );

        $body     = $request->input('body', '');
        $bodyHtml = $request->input('body_html') ?: nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));

        $data = [
            'customer_id'       => $customer['id'],
            'subject'           => $request->input('subject'),
            'priority'          => $request->input('priority', 'normal'),
            'channel'           => $request->input('channel', 'phone'),
            'body_html'         => $bodyHtml,
            'assigned_agent_id' => $request->input('assigned_agent_id'),
            'parent_ticket_id'  => $request->input('parent_ticket_id'),
        ];

        $result = $this->service->createFromAgent($data, $request->agent->id);
        Response::created($result['ticket'], 'Ticket created');
    }

    public function show(Request $request, array $params): void
    {
        $ticket = $this->service->getWithFullThread((int)$params['id']);
        if (!$ticket) throw new NotFoundException('Ticket not found');
        Response::success($ticket);
    }

    public function update(Request $request, array $params): void
    {
        $ticket = $this->repo->findById((int)$params['id']);
        if (!$ticket) throw new NotFoundException('Ticket not found');

        $allowed = ['subject', 'priority', 'assigned_agent_id', 'customer_id', 'suppress_emails'];
        $data    = [];
        foreach ($allowed as $field) {
            if ($request->input($field) !== null) {
                $data[$field] = $request->input($field);
            }
        }

        if (isset($data['suppress_emails'])) {
            $data['suppress_emails'] = (int)(bool)$data['suppress_emails'];
        }

        if (isset($data['subject']) && strlen($data['subject']) > 255) {
            throw new HttpException('Subject must not exceed 255 characters', 422);
        }

        $newCustomer = null;
        if (isset($data['customer_id'])) {
            $customerRepo = new \Andrea\Helpdesk\Customers\CustomerRepository();
            $newCustomer  = $customerRepo->findById((int)$data['customer_id']);
            if (!$newCustomer) throw new HttpException('Customer not found', 422);
            $data['customer_id'] = (int)$data['customer_id'];
        }

        $this->repo->update($ticket['id'], $data);

        // Log audit trail for changes
        $replyService = new ReplyService();
        if (isset($data['subject']) && $data['subject'] !== $ticket['subject']) {
            $replyService->createSystemReply($ticket['id'], "Subject changed to \"{$data['subject']}\".", $request->agent->id);
        }
        if ($newCustomer && (int)$data['customer_id'] !== (int)$ticket['customer_id']) {
            $label = $newCustomer['name'] ?: $newCustomer['email'];
            $replyService->createSystemReply($ticket['id'], "Customer changed to {$label}.", $request->agent->id);
        }
        if (isset($data['suppress_emails']) && $data['suppress_emails'] !== (int)$ticket['suppress_emails']) {
            $msg = $data['suppress_emails'] ? 'Email suppression enabled — no outbound emails will be sent for this ticket.' : 'Email suppression disabled — outbound emails resumed.';
            $replyService->createSystemReply($ticket['id'], $msg, $request->agent->id);
        }

        // If assignment changed, notify new agent
        if (isset($data['assigned_agent_id']) && $data['assigned_agent_id'] != $ticket['assigned_agent_id']) {
            $agent = $this->db->fetch("SELECT * FROM agents WHERE id = ?", [$data['assigned_agent_id']]);
            if ($agent) {
                try {
                    $notifications = new NotificationService();
                    $notifications->onTicketAssigned($ticket, $agent);
                } catch (\Throwable) {}
            }
        }

        Response::success($this->repo->findById($ticket['id']), 'Ticket updated');
    }

    public function updateReply(Request $request, array $params): void
    {
        $ticketId = (int)$params['id'];
        $replyId  = (int)$params['reply_id'];

        $reply = $this->db->fetch(
            "SELECT id, author_type, agent_id FROM replies WHERE id = ? AND ticket_id = ? AND author_type != 'system'",
            [$replyId, $ticketId]
        );
        if (!$reply) throw new NotFoundException('Reply not found');

        // Only allow editing agent replies; admins may edit any agent reply, agents only their own
        if ($reply['author_type'] !== 'agent') {
            throw new HttpException('Customer replies cannot be edited', 403);
        }
        if ($request->agent->role !== 'admin' && (int)$reply['agent_id'] !== $request->agent->id) {
            throw new HttpException('You can only edit your own replies', 403);
        }

        $body     = trim($request->input('body', ''));
        $bodyHtml = $request->input('body_html') ?: nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
        $this->db->execute(
            "UPDATE replies SET body_html = ?, updated_at = NOW() WHERE id = ?",
            [$bodyHtml, $replyId]
        );

        $replyService = new ReplyService();
        $replyService->createSystemReply($ticketId, 'Message body updated.', $request->agent->id);

        Response::success(null, 'Reply updated');
    }

    public function destroy(Request $request, array $params): void
    {
        $ticket = $this->repo->findById((int)$params['id']);
        if (!$ticket) throw new NotFoundException('Ticket not found');

        $this->deleteTicketCascade($ticket['id']);
        Response::success(null, 'Ticket deleted');
    }

    private function deleteTicketCascade(int $ticketId): void
    {
        // Delete child tickets recursively
        $children = $this->db->fetchAll(
            "SELECT id FROM tickets WHERE parent_ticket_id = ? AND deleted_at IS NULL",
            [$ticketId]
        );
        foreach ($children as $child) {
            $this->deleteTicketCascade($child['id']);
        }

        // Delete physical attachment files and records
        $attachmentService = new AttachmentService();
        $attachments = $this->db->fetchAll(
            "SELECT id FROM attachments WHERE ticket_id = ?",
            [$ticketId]
        );
        foreach ($attachments as $att) {
            $attachmentService->delete($att['id']);
        }

        $this->repo->softDelete($ticketId);
    }

    public function assign(Request $request, array $params): void
    {
        $ticket   = $this->repo->findById((int)$params['id']);
        if (!$ticket) throw new NotFoundException('Ticket not found');

        $agentId = $request->input('agent_id');
        $this->repo->update($ticket['id'], ['assigned_agent_id' => $agentId ?: null]);

        if ($agentId) {
            $agent = $this->db->fetch("SELECT * FROM agents WHERE id = ?", [$agentId]);
            if ($agent) {
                try {
                    $notifications = new NotificationService();
                    $notifications->onTicketAssigned($ticket, $agent);
                } catch (\Throwable) {}
            }
        }

        Response::success($this->repo->findById($ticket['id']), 'Ticket assigned');
    }

    public function status(Request $request, array $params): void
    {
        $ticket = $this->repo->findById((int)$params['id']);
        if (!$ticket) throw new NotFoundException('Ticket not found');

        $status = $request->input('status');
        if (!$status) throw new HttpException('status is required', 400);

        // Check close permission
        if (in_array($status, ['closed', 'resolved'], true) && $request->agent->role !== 'admin') {
            if (!$request->agent->can_close_tickets) {
                throw new HttpException('You do not have permission to close tickets', 403);
            }
        }

        $this->service->updateStatus($ticket['id'], $status, $request->agent->id);
        Response::success($this->repo->findById($ticket['id']), 'Status updated');
    }

    public function merge(Request $request, array $params): void
    {
        $ticket   = $this->repo->findById((int)$params['id']);
        if (!$ticket) throw new NotFoundException('Ticket not found');

        $targetId = (int)$request->input('target_ticket_id');
        if (!$targetId) throw new HttpException('target_ticket_id is required', 400);

        $target = $this->repo->findById($targetId);
        if (!$target) throw new NotFoundException('Target ticket not found');

        $this->service->mergeTickets($ticket['id'], $targetId, $request->agent->id);
        Response::success(null, "Ticket merged into {$target['ticket_number']}");
    }

    public function relate(Request $request, array $params): void
    {
        $ticket    = $this->repo->findById((int)$params['id']);
        if (!$ticket) throw new NotFoundException('Ticket not found');

        $relatedId = (int)$request->input('related_ticket_id');
        $related   = $this->repo->findById($relatedId);
        if (!$related) throw new NotFoundException('Related ticket not found');

        $this->repo->addRelation($ticket['id'], $relatedId);
        Response::success(null, 'Tickets linked');
    }

    public function unrelate(Request $request, array $params): void
    {
        $this->repo->removeRelation((int)$params['id'], (int)$params['related_id']);
        Response::success(null, 'Ticket link removed');
    }

    public function spawn(Request $request, array $params): void
    {
        $ticket = $this->repo->findById((int)$params['id']);
        if (!$ticket) throw new NotFoundException('Ticket not found');

        $data = $request->validate(['subject' => 'required']);
        $data['priority']    = $request->input('priority', 'normal');
        $data['body_html']   = $request->input('body_html', '');
        $data['customer_id'] = $request->input('customer_id') ?? $ticket['customer_id'];

        $result = $this->service->createChildTicket($ticket['id'], $data, $request->agent->id);
        Response::created($result['ticket'], 'Sub-ticket created');
    }

    public function toKb(Request $request, array $params): void
    {
        $article = $this->service->moveToKnowledgeBase((int)$params['id'], $request->agent->id);
        Response::created($article, 'Added to knowledge base');
    }

    public function participants(Request $request, array $params): void
    {
        $participants = $this->repo->getParticipants((int)$params['id']);
        Response::success($participants);
    }

    public function addParticipant(Request $request, array $params): void
    {
        $data  = $request->validate(['email' => 'required|email']);
        $name  = $request->input('name', '');
        $ticketId = (int)$params['id'];

        // Upsert customer
        $customerRepo = new \Andrea\Helpdesk\Customers\CustomerRepository();
        $customer     = $customerRepo->upsertByEmail($data['email'], $name);

        $this->repo->addParticipant($ticketId, $data['email'], $name, 'cc', $customer['id']);
        Response::success($this->repo->getParticipants($ticketId));
    }

    public function removeParticipant(Request $request, array $params): void
    {
        $this->repo->removeParticipant((int)$params['participant_id']);
        Response::success(null, 'Participant removed');
    }

    public function addTags(Request $request, array $params): void
    {
        $ticketId = (int)$params['id'];
        $tagRepo  = new \Andrea\Helpdesk\Tickets\TagRepository();

        // Accept either a name (single tag) or tag_ids (array of existing IDs)
        $name = $request->input('name');
        if ($name) {
            $tag = $tagRepo->findByName(trim($name)) ?? $tagRepo->findById($tagRepo->create(trim($name)));
            $this->repo->addTag($ticketId, (int)$tag['id']);
        } else {
            foreach ((array)$request->input('tag_ids', []) as $tagId) {
                $this->repo->addTag($ticketId, (int)$tagId);
            }
        }

        Response::success($this->repo->getTags($ticketId));
    }

    public function removeTag(Request $request, array $params): void
    {
        $this->repo->removeTag((int)$params['id'], (int)$params['tag_id']);
        Response::success(null, 'Tag removed');
    }
}
