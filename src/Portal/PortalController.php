<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Portal;

use Andrea\Helpdesk\Core\Database;
use Andrea\Helpdesk\Core\Request;
use Andrea\Helpdesk\Core\Response;
use Andrea\Helpdesk\Core\Exceptions\NotFoundException;
use Andrea\Helpdesk\Core\Exceptions\HttpException;
use Andrea\Helpdesk\Tickets\ReplyRepository;
use Andrea\Helpdesk\Tickets\ReplyService;
use Andrea\Helpdesk\Tickets\AttachmentService;

class PortalController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * GET /api/portal/tickets
     * Returns tickets where this customer is the requester or a CC participant.
     */
    public function index(Request $request): void
    {
        $customerId = $request->customer->id;
        $email      = $request->customer->email;
        $page       = max(1, (int)$request->input('page', 1));
        $perPage    = min(50, max(1, (int)$request->input('per_page', 20)));
        $offset     = ($page - 1) * $perPage;

        $total = $this->db->count(
            "SELECT COUNT(DISTINCT t.id) FROM tickets t
             LEFT JOIN ticket_participants tp ON tp.ticket_id = t.id
             WHERE t.deleted_at IS NULL
               AND (t.customer_id = ? OR tp.email = ?)",
            [$customerId, $email]
        );

        $items = $this->db->fetchAll(
            "SELECT DISTINCT t.id, t.ticket_number, t.subject, t.status, t.priority, t.created_at, t.updated_at,
                    (SELECT COUNT(*) FROM replies r WHERE r.ticket_id = t.id AND r.is_private = 0) AS reply_count
             FROM tickets t
             LEFT JOIN ticket_participants tp ON tp.ticket_id = t.id
             WHERE t.deleted_at IS NULL
               AND (t.customer_id = ? OR tp.email = ?)
             ORDER BY t.updated_at DESC LIMIT {$perPage} OFFSET {$offset}",
            [$customerId, $email]
        );

        Response::paginated($items, $total, $page, $perPage);
    }

    /**
     * GET /api/portal/tickets/:id
     */
    public function show(Request $request, array $params): void
    {
        $ticket = $this->getAccessibleTicket((int)$params['id'], $request->customer);
        if (!$ticket) throw new NotFoundException('Ticket not found');

        $replyRepo = new ReplyRepository();
        $ticket['replies']     = $replyRepo->findByTicketId($ticket['id'], false); // no private notes
        $ticket['attachments'] = (new AttachmentService())->getAttachmentsForTicket($ticket['id']);

        // Remove sensitive/internal fields
        unset($ticket['original_message_id'], $ticket['last_message_id'], $ticket['reply_to_address']);

        Response::success($ticket);
    }

    /**
     * POST /api/portal/tickets/:id/replies
     */
    public function reply(Request $request, array $params): void
    {
        $ticket = $this->getAccessibleTicket((int)$params['id'], $request->customer);
        if (!$ticket) throw new NotFoundException('Ticket not found');

        if ($ticket['status'] === 'closed') {
            throw new HttpException('This ticket is closed', 400);
        }

        $data = $request->validate(['body_html' => 'required']);

        $replyService = new ReplyService();
        $reply        = $replyService->createCustomerReply(
            $ticket['id'],
            $request->customer->id,
            $data['body_html']
        );

        Response::created($reply, 'Reply added');
    }

    /**
     * POST /api/portal/tickets/:id/attachments
     */
    public function attachment(Request $request, array $params): void
    {
        $ticket = $this->getAccessibleTicket((int)$params['id'], $request->customer);
        if (!$ticket) throw new NotFoundException('Ticket not found');

        if (empty($request->files)) {
            throw new HttpException('No file uploaded', 400);
        }

        $service  = new AttachmentService();
        $uploaded = [];

        foreach ($request->files as $file) {
            if (is_array($file['name'] ?? null)) {
                for ($i = 0; $i < count($file['name']); $i++) {
                    $uploaded[] = $service->store(
                        $ticket['id'],
                        ['name' => $file['name'][$i], 'tmp_name' => $file['tmp_name'][$i],
                         'type' => $file['type'][$i], 'size' => $file['size'][$i], 'error' => $file['error'][$i]],
                        null, null, $request->customer->id
                    );
                }
            } else {
                $uploaded[] = $service->store($ticket['id'], $file, null, null, $request->customer->id);
            }
        }

        Response::created($uploaded, 'Attachment uploaded');
    }

    private function getAccessibleTicket(int $ticketId, object $customer): ?array
    {
        $ticket = $this->db->fetch(
            "SELECT t.* FROM tickets t WHERE t.id = ? AND t.deleted_at IS NULL",
            [$ticketId]
        );

        if (!$ticket) return null;

        // Check access
        if ($ticket['customer_id'] == $customer->id) return $ticket;

        $participant = $this->db->fetch(
            "SELECT id FROM ticket_participants WHERE ticket_id = ? AND email = ?",
            [$ticketId, $customer->email]
        );

        return $participant ? $ticket : null;
    }
}
