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

        $request->validate(['body' => 'required']);

        $body      = $request->input('body', '');
        $bodyHtml  = $request->input('body_html') ?? nl2br(htmlspecialchars($body, ENT_QUOTES));
        $type      = $request->input('type', 'reply');
        $isPrivate = $type === 'internal' || (bool)$request->input('is_private', false);
        $ccEmails  = $request->input('cc_emails', []);

        // Save any uploaded files before creating the reply so they can be emailed
        $attachmentIds = [];
        if (!empty($request->files)) {
            $attachService = new AttachmentService();
            foreach ($request->files as $file) {
                $fileList = isset($file['name']) && is_array($file['name'])
                    ? array_map(fn($i) => ['name' => $file['name'][$i], 'tmp_name' => $file['tmp_name'][$i], 'type' => $file['type'][$i], 'size' => $file['size'][$i], 'error' => $file['error'][$i]], range(0, count($file['name']) - 1))
                    : [$file];
                foreach ($fileList as $f) {
                    if ($f['error'] === UPLOAD_ERR_OK) {
                        $saved           = $attachService->store($ticket['id'], $f, null, $request->agent->id);
                        $attachmentIds[] = $saved['id'];
                    }
                }
            }
        }

        $reply = $this->service->createAgentReply(
            $ticket['id'],
            $request->agent->id,
            $bodyHtml,
            $isPrivate,
            is_array($ccEmails) ? $ccEmails : [],
            $attachmentIds
        );

        // Link attachments to the reply now that we have its ID
        if ($attachmentIds && $reply) {
            $db = \Andrea\Helpdesk\Core\Database::getInstance();
            foreach ($attachmentIds as $aid) {
                $db->execute("UPDATE attachments SET reply_id = ? WHERE id = ?", [$reply['id'], $aid]);
            }
        }

        Response::created($reply, 'Reply added');
    }
}
