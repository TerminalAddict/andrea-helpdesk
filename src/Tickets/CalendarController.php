<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Tickets;

use Andrea\Helpdesk\Core\Database;
use Andrea\Helpdesk\Core\Request;
use Andrea\Helpdesk\Core\Response;

class CalendarController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * GET /api/calendar/token
     * Returns the agent's iCal subscription token and ready-to-use URLs.
     */
    public function token(Request $request): void
    {
        $agentId   = $request->agent->id;
        $token     = $this->makeToken($agentId);
        $base      = $this->baseUrl();
        $path      = '/api/calendar/ical?agent_id=' . $agentId . '&token=' . urlencode($token);

        Response::success([
            'token'     => $token,
            'agent_id'  => $agentId,
            'ical_url'  => $base . $path,
            'webcal_url'=> preg_replace('#^https?://#', 'webcal://', $base) . $path,
        ]);
    }

    /**
     * GET /api/calendar/events?from=YYYY-MM-DD&to=YYYY-MM-DD
     * Returns tickets with due dates in the given range (for the in-app calendar).
     */
    public function events(Request $request): void
    {
        $from = $request->input('from');
        $to   = $request->input('to');

        $where  = ['t.deleted_at IS NULL', 't.due_at IS NOT NULL'];
        $params = [];

        if ($from) {
            $where[]  = 't.due_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if ($to) {
            $where[]  = 't.due_at <= ?';
            $params[] = $to . ' 23:59:59';
        }

        $tickets = $this->db->fetchAll(
            "SELECT t.id, t.ticket_number, t.subject, t.status, t.priority,
                    t.due_at, t.due_end, t.due_all_day,
                    c.name AS customer_name
             FROM tickets t
             LEFT JOIN customers c ON c.id = t.customer_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY t.due_at ASC",
            $params
        );

        Response::success($tickets);
    }

    /**
     * GET /api/calendar/ical?agent_id=N&token=...
     * Returns an iCal feed of all open tickets with due dates.
     * Authenticated by HMAC token (no JWT, so calendar apps can subscribe).
     */
    public function ical(Request $request): void
    {
        $agentId = (int)$request->input('agent_id');
        $token   = (string)$request->input('token', '');

        if (!$agentId || !$this->verifyToken($agentId, $token)) {
            http_response_code(401);
            header('Content-Type: text/plain');
            echo 'Unauthorized';
            exit;
        }

        $tickets = $this->db->fetchAll(
            "SELECT t.id, t.ticket_number, t.subject, t.status, t.priority,
                    t.due_at, t.due_end, t.due_all_day,
                    c.name AS customer_name
             FROM tickets t
             LEFT JOIN customers c ON c.id = t.customer_id
             WHERE t.deleted_at IS NULL
               AND t.due_at IS NOT NULL
               AND t.status NOT IN ('resolved', 'closed')
             ORDER BY t.due_at ASC",
            []
        );

        $base = $this->baseUrl();
        $now  = gmdate('Ymd\THis\Z');

        // Fetch calendar name from settings
        $nameRow  = $this->db->fetch("SELECT value FROM settings WHERE key_name = 'company_name'");
        $appName  = ($nameRow && $nameRow['value']) ? $nameRow['value'] : 'Andrea Helpdesk';

        $lines   = [];
        $lines[] = 'BEGIN:VCALENDAR';
        $lines[] = 'VERSION:2.0';
        $lines[] = 'PRODID:-//Andrea Helpdesk//EN';
        $lines[] = 'CALSCALE:GREGORIAN';
        $lines[] = 'METHOD:PUBLISH';
        $lines[] = 'X-WR-CALNAME:' . $this->escVal($appName . ' — Due Dates');
        $lines[] = 'X-WR-CALDESC:Tickets with due dates from ' . $this->escVal($appName);
        $lines[] = 'REFRESH-INTERVAL;VALUE=DURATION:PT1H';
        $lines[] = 'X-PUBLISHED-TTL:PT1H';

        foreach ($tickets as $t) {
            $uid     = 'ticket-' . $t['id'] . '@andrea-helpdesk';
            $summary = '[' . $t['ticket_number'] . '] ' . $t['subject'];
            $url     = $base . '/#/tickets/' . $t['id'];
            $desc    = 'Status: ' . $t['status']
                . '\\nPriority: ' . $t['priority']
                . ($t['customer_name'] ? '\\nCustomer: ' . $t['customer_name'] : '')
                . '\\nURL: ' . $url;

            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . $uid;
            $lines[] = 'DTSTAMP:' . $now;

            if ($t['due_all_day']) {
                $start = date('Ymd', strtotime($t['due_at']));
                $end   = $t['due_end']
                    ? date('Ymd', strtotime($t['due_end']) + 86400)
                    : date('Ymd', strtotime($t['due_at']) + 86400);
                $lines[] = 'DTSTART;VALUE=DATE:' . $start;
                $lines[] = 'DTEND;VALUE=DATE:' . $end;
            } else {
                $start = date('Ymd\THis', strtotime($t['due_at']));
                $end   = $t['due_end']
                    ? date('Ymd\THis', strtotime($t['due_end']))
                    : date('Ymd\THis', strtotime($t['due_at']) + 3600);
                $lines[] = 'DTSTART:' . $start;
                $lines[] = 'DTEND:' . $end;
            }

            $lines[] = 'SUMMARY:' . $this->escVal($summary);
            $lines[] = 'DESCRIPTION:' . $this->escVal($desc);
            $lines[] = 'URL:' . $url;
            $lines[] = 'CLASS:PRIVATE';

            // Priority mapping
            $icalPriority = ['urgent' => 1, 'high' => 3, 'normal' => 5, 'low' => 9];
            $lines[] = 'PRIORITY:' . ($icalPriority[$t['priority']] ?? 5);

            // Reminder: 1 day before
            $lines[] = 'BEGIN:VALARM';
            $lines[] = 'ACTION:DISPLAY';
            $lines[] = 'DESCRIPTION:Due tomorrow: ' . $this->escVal($summary);
            $lines[] = 'TRIGGER:-P1D';
            $lines[] = 'END:VALARM';

            // Reminder: 1 hour before
            $lines[] = 'BEGIN:VALARM';
            $lines[] = 'ACTION:DISPLAY';
            $lines[] = 'DESCRIPTION:Due in 1 hour: ' . $this->escVal($summary);
            $lines[] = 'TRIGGER:-PT1H';
            $lines[] = 'END:VALARM';

            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        $ical = implode("\r\n", $lines) . "\r\n";

        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: inline; filename="andrea-helpdesk.ics"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        echo $ical;
        exit;
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function makeToken(int $agentId): string
    {
        return hash_hmac('sha256', 'ical:' . $agentId, (string)getenv('JWT_SECRET'));
    }

    private function verifyToken(int $agentId, string $token): bool
    {
        if (!$token) return false;
        $agent = $this->db->fetch(
            "SELECT id FROM agents WHERE id = ? AND is_active = 1",
            [$agentId]
        );
        if (!$agent) return false;
        return hash_equals($this->makeToken($agentId), $token);
    }

    private function baseUrl(): string
    {
        try {
            $row = $this->db->fetch("SELECT value FROM settings WHERE key_name = 'app_url'");
            if ($row && !empty($row['value'])) {
                return rtrim($row['value'], '/');
            }
        } catch (\Throwable) {}
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    /**
     * Escape a string value for iCal (RFC 5545).
     * Real newlines are stripped first to prevent CRLF injection into the feed.
     */
    private function escVal(string $s): string
    {
        $s = str_replace(["\r\n", "\r", "\n"], ' ', $s);
        return str_replace(['\\', ';', ','], ['\\\\', '\\;', '\\,'], $s);
    }
}
