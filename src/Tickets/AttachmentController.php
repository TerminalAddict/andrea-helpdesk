<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Tickets;

use Andrea\Helpdesk\Core\Database;
use Andrea\Helpdesk\Core\Request;
use Andrea\Helpdesk\Core\Response;
use Andrea\Helpdesk\Core\Exceptions\NotFoundException;
use Andrea\Helpdesk\Core\Exceptions\HttpException;

class AttachmentController
{
    private AttachmentService $service;
    private Database $db;

    public function __construct()
    {
        $this->service = new AttachmentService();
        $this->db      = Database::getInstance();
    }

    public function store(Request $request, array $params): void
    {
        $ticket = $this->db->fetch(
            "SELECT * FROM tickets WHERE id = ? AND deleted_at IS NULL",
            [(int)$params['id']]
        );
        if (!$ticket) throw new NotFoundException('Ticket not found');

        if (empty($request->files)) {
            throw new HttpException('No file uploaded', 400);
        }

        $replyId  = $request->input('reply_id') ? (int)$request->input('reply_id') : null;
        $uploaded = [];

        foreach ($request->files as $fileKey => $file) {
            // Handle multiple files with same key (file[])
            if (isset($file['name']) && is_array($file['name'])) {
                for ($i = 0; $i < count($file['name']); $i++) {
                    $singleFile = [
                        'name'     => $file['name'][$i],
                        'tmp_name' => $file['tmp_name'][$i],
                        'type'     => $file['type'][$i],
                        'size'     => $file['size'][$i],
                        'error'    => $file['error'][$i],
                    ];
                    $uploaded[] = $this->service->store($ticket['id'], $singleFile, $replyId, $request->agent->id);
                }
            } else {
                $uploaded[] = $this->service->store($ticket['id'], $file, $replyId, $request->agent->id);
            }
        }

        Response::created($uploaded, 'Attachment(s) uploaded');
    }

    public function destroy(Request $request, array $params): void
    {
        $attachment = $this->db->fetch("SELECT * FROM attachments WHERE id = ?", [(int)$params['id']]);
        if (!$attachment) throw new NotFoundException('Attachment not found');

        // Verify access: agent must have access to the ticket
        $ticket = $this->db->fetch(
            "SELECT * FROM tickets WHERE id = ? AND deleted_at IS NULL",
            [$attachment['ticket_id']]
        );
        if (!$ticket) throw new NotFoundException('Ticket not found');

        $this->service->delete($attachment['id']);
        Response::success(null, 'Attachment deleted');
    }
}
