<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Tickets;

use Andrea\Helpdesk\Core\Request;
use Andrea\Helpdesk\Core\Response;
use Andrea\Helpdesk\Core\Exceptions\NotFoundException;

class ReplyController
{
    private ReplyRepository $repo;
    private ReplyService $service;
    private TicketRepository $ticketRepo;

    public function __construct()
    {
        $this->repo       = new ReplyRepository();
        $this->service    = new ReplyService();
        $this->ticketRepo = new TicketRepository();
    }

    public function index(Request $request, array $params): void
    {
        $ticket = $this->ticketRepo->findById((int)$params['id']);
        if (!$ticket) throw new NotFoundException('Ticket not found');

        $includePrivate = $request->agent !== null;
        $replies        = $this->repo->findByTicketId($ticket['id'], $includePrivate);
        Response::success($replies);
    }

    public function store(Request $request, array $params): void
    {
        $ticket = $this->ticketRepo->findById((int)$params['id']);
        if (!$ticket) throw new NotFoundException('Ticket not found');

        $data = $request->validate(['body_html' => 'required']);

        $isPrivate = (bool)$request->input('is_private', false);
        $ccEmails  = $request->input('cc_emails', []);

        $reply = $this->service->createAgentReply(
            $ticket['id'],
            $request->agent->id,
            $data['body_html'],
            $isPrivate,
            is_array($ccEmails) ? $ccEmails : []
        );

        Response::created($reply, 'Reply added');
    }
}
