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
        $data = $request->validate([
            'customer_id' => 'required|integer',
            'subject'     => 'required|max:255',
        ]);

        $data['priority'] = $request->input('priority', 'normal');
        $data['channel']  = $request->input('channel', 'phone');
        $data['body_html']= $request->input('body_html', '');

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

        $allowed = ['subject', 'priority', 'assigned_agent_id'];
        $data    = [];
        foreach ($allowed as $field) {
            if ($request->input($field) !== null) {
                $data[$field] = $request->input($field);
            }
        }

        $this->repo->update($ticket['id'], $data);

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

    public function destroy(Request $request, array $params): void
    {
        $ticket = $this->repo->findById((int)$params['id']);
        if (!$ticket) throw new NotFoundException('Ticket not found');
        $this->repo->softDelete($ticket['id']);
        Response::success(null, 'Ticket deleted');
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
        $tagIds   = $request->input('tag_ids', []);
        $ticketId = (int)$params['id'];
        foreach ((array)$tagIds as $tagId) {
            $this->repo->addTag($ticketId, (int)$tagId);
        }
        Response::success($this->repo->getTags($ticketId));
    }

    public function removeTag(Request $request, array $params): void
    {
        $this->repo->removeTag((int)$params['id'], (int)$params['tag_id']);
        Response::success(null, 'Tag removed');
    }
}
