<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Portal;

use Andrea\Helpdesk\Core\Database;
use Andrea\Helpdesk\Core\Request;
use Andrea\Helpdesk\Core\Response;
use Andrea\Helpdesk\Core\Exceptions\NotFoundException;
use Andrea\Helpdesk\Core\Exceptions\HttpException;
use Andrea\Helpdesk\Tickets\TicketRepository;
use Andrea\Helpdesk\Tickets\TicketService;
use Andrea\Helpdesk\Tickets\ReplyRepository;
use Andrea\Helpdesk\Tickets\ReplyService;
use Andrea\Helpdesk\Tickets\AttachmentService;
use Andrea\Helpdesk\Customers\CustomerRepository;
use Andrea\Helpdesk\Notifications\NotificationService;
use Andrea\Helpdesk\Settings\SettingsService;

class PortalController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * POST /api/support-form
     * Create a new public ticket from the website support form.
     */
    public function publicCreate(Request $request): void
    {
        $data = $request->validate([
            'name'    => 'required|max:255',
            'email'   => 'required|email',
            'subject' => 'required|max:255',
            'message' => 'required',
        ]);

        $this->assertSupportFormHuman($request);

        $ticketService = new TicketService();
        $result = $ticketService->createFromEmail([
            'from_email' => $data['email'],
            'from_name'  => $data['name'],
            'subject'    => $data['subject'],
            'body_text'  => $data['message'],
            'body_html'  => $request->input('body_html')
                ? \Andrea\Helpdesk\Core\Sanitizer::html((string)$request->input('body_html'))
                : nl2br(htmlspecialchars((string)$data['message'], ENT_QUOTES, 'UTF-8')),
            'reply_to'   => $data['email'],
            'channel'    => 'web',
            'cc_emails'  => [],
        ]);

        if (!empty($request->files)) {
            $service  = new AttachmentService();
            $uploaded = [];
            foreach ($request->files as $file) {
                if (is_array($file['name'] ?? null)) {
                    for ($i = 0; $i < count($file['name']); $i++) {
                        $uploaded[] = $service->store(
                            (int)$result['ticket']['id'],
                            [
                                'name' => $file['name'][$i],
                                'tmp_name' => $file['tmp_name'][$i],
                                'type' => $file['type'][$i],
                                'size' => $file['size'][$i],
                                'error' => $file['error'][$i],
                            ],
                            $result['initial_reply_id'] ? (int)$result['initial_reply_id'] : null,
                            null,
                            !empty($result['customer']['id']) ? (int)$result['customer']['id'] : null
                        );
                    }
                } else {
                    $uploaded[] = $service->store(
                        (int)$result['ticket']['id'],
                        $file,
                        $result['initial_reply_id'] ? (int)$result['initial_reply_id'] : null,
                        null,
                        !empty($result['customer']['id']) ? (int)$result['customer']['id'] : null
                    );
                }
            }
        }

        Response::created($result['ticket'], 'Support request submitted');
    }

    /**
     * GET /api/support-form/challenge
     * Returns a lightweight human challenge when reCAPTCHA is not configured.
     */
    public function challenge(Request $request): void
    {
        $left = random_int(2, 9);
        $right = random_int(1, 9);
        $payload = [
            'a' => $left,
            'b' => $right,
            'exp' => time() + 1800,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $token = base64_encode($json) . '.' . hash_hmac('sha256', $json, (string)getenv('JWT_SECRET'));

        Response::success([
            'question' => "What is {$left} + {$right}?",
            'token' => $token,
        ]);
    }

    /**
     * POST /api/portal/tickets
     * Create a new ticket from the customer portal.
     */
    public function create(Request $request): void
    {
        $data = $request->validate([
            'subject' => 'required|max:255',
            'body'    => 'required',
        ]);

        $customer      = (new CustomerRepository())->findById($request->customer->id);
        $ticketRepo    = new TicketRepository();
        $ticketService = new TicketService();
        $replyRepo     = new ReplyRepository();

        $this->db->beginTransaction();
        try {
            $ticketNumber = $ticketService->generateNumber();
            $ticketId     = $ticketRepo->create([
                'ticket_number' => $ticketNumber,
                'subject'       => $data['subject'],
                'channel'       => 'portal',
                'customer_id'   => $customer['id'],
                'status'        => 'new',
                'priority'      => 'normal',
            ]);

            $bodyText = $data['body'];
            $rawHtml  = $request->input('body_html');
            $bodyHtml = $rawHtml
                ? \Andrea\Helpdesk\Core\Sanitizer::html($rawHtml)
                : nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8'));

            $replyRepo->create([
                'ticket_id'   => $ticketId,
                'author_type' => 'customer',
                'customer_id' => $customer['id'],
                'body_html'   => $bodyHtml,
                'body_text'   => $bodyText,
                'is_private'  => 0,
                'direction'   => 'inbound',
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        $ticket = $ticketRepo->findById($ticketId);

        try {
            (new NotificationService())->onNewTicket($ticket, $customer);
        } catch (\Throwable) {}

        Response::created($ticket, 'Ticket created');
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

        $data = $request->validate(['body' => 'required']);

        $bodyText = $data['body'];
        $rawHtml  = $request->input('body_html');
        $bodyHtml = $rawHtml
            ? \Andrea\Helpdesk\Core\Sanitizer::html($rawHtml)
            : nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8'));

        $replyService = new ReplyService();
        $reply        = $replyService->createCustomerReply(
            $ticket['id'],
            $request->customer->id,
            $bodyHtml,
            $bodyText
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

    private function assertSupportFormHuman(Request $request): void
    {
        $config = SettingsService::getInstance()->getSupportFormConfig();
        $honeypot = trim((string)$request->input('website', ''));
        if ($honeypot !== '') {
            throw new HttpException('Support form verification failed', 422);
        }

        $startedAt = (int)$request->input('started_at', 0);
        if ($startedAt > 0 && ((int)floor(microtime(true) * 1000) - $startedAt) < 1500) {
            throw new HttpException('Support form submitted too quickly. Please try again.', 422);
        }

        if (!empty($config['recaptcha_site_key']) && !empty($config['recaptcha_secret_key'])) {
            $token = trim((string)$request->input('recaptcha_token', ''));
            if ($token === '') {
                throw new HttpException('reCAPTCHA verification failed', 422);
            }
            $this->verifyRecaptcha($token, $request->ip(), (string)$config['recaptcha_secret_key']);
            return;
        }

        $challengeToken = trim((string)$request->input('human_check_token', ''));
        $challengeAnswer = trim((string)$request->input('human_check_answer', ''));
        if ($challengeToken === '' || $challengeAnswer === '') {
            throw new HttpException('Please complete the human verification check.', 422);
        }
        $this->verifyHumanChallenge($challengeToken, $challengeAnswer);
    }

    private function verifyRecaptcha(string $token, string $ip, string $secret): void
    {
        $postFields = http_build_query([
            'secret' => $secret,
            'response' => $token,
            'remoteip' => $ip,
        ]);

        $response = false;
        if (function_exists('curl_init')) {
            $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postFields,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
        }

        if ($response === false) {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                    'content' => $postFields,
                    'timeout' => 10,
                ],
            ]);
            $response = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $context);
        }

        $json = json_decode((string)$response, true);
        if (
            !is_array($json)
            || empty($json['success'])
            || (($json['score'] ?? 0) < 0.5)
            || (!empty($json['action']) && $json['action'] !== 'support_form_submit')
        ) {
            throw new HttpException('reCAPTCHA verification failed', 422);
        }
    }

    private function verifyHumanChallenge(string $token, string $answer): void
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            throw new HttpException('Human verification failed', 422);
        }

        [$payloadB64, $sig] = $parts;
        $json = base64_decode($payloadB64, true);
        if ($json === false) {
            throw new HttpException('Human verification failed', 422);
        }

        $expectedSig = hash_hmac('sha256', $json, (string)getenv('JWT_SECRET'));
        if (!hash_equals($expectedSig, $sig)) {
            throw new HttpException('Human verification failed', 422);
        }

        $payload = json_decode($json, true);
        if (!is_array($payload) || ($payload['exp'] ?? 0) < time()) {
            throw new HttpException('Human verification expired. Please try again.', 422);
        }

        $expected = (int)($payload['a'] ?? 0) + (int)($payload['b'] ?? 0);
        if ((string)$expected !== trim($answer)) {
            throw new HttpException('Human verification answer is incorrect.', 422);
        }
    }
}
