<?php
declare(strict_types=1);

// Isolated notification boundary test: no database, email, Slack or push is contacted.
namespace Andrea\Helpdesk\Core {
    class Database
    {
        public static function getInstance(): self { return new self(); }
        public function fetchAll(string $sql, array $params = []): array
        {
            return [['id' => 7, 'email' => 'agent@example.test', 'name' => 'Agent', 'role' => 'agent']];
        }
    }
}

namespace Andrea\Helpdesk\Settings {
    class SettingsService
    {
        public static function getInstance(): self { return new self(); }
        public function getEmailConfig(): array { return ['notify_agent_on_new_ticket' => true]; }
    }
}

namespace Andrea\Helpdesk\Agents {
    class AgentRepository
    {
        public function getActiveAgents(): array { return [['id' => 7]]; }
    }
}

namespace Andrea\Helpdesk\Notifications {
    class EmailNotifier
    {
        public static int $sent = 0;
        public function sendNewTicketNotification(...$args): void { self::$sent++; }
    }
    class SlackNotifier
    {
        public static int $sent = 0;
        public function __construct(...$args) {}
        public function sendNewTicketAlert(...$args): void { self::$sent++; }
    }
    class AutoResponder
    {
        public static int $sent = 0;
        public function __construct(...$args) {}
        public function sendForNewTicket(...$args): void { self::$sent++; }
    }
    class AgentNotificationRepository
    {
        public static int $sent = 0;
        public function createForAgents(array $ids, array $payload): array
        {
            self::$sent++;
            return $ids;
        }
    }
    class PushNotificationService
    {
        public static int $sent = 0;
        public function __construct(...$args) {}
        public function sendToAgents(...$args): void { self::$sent++; }
    }
}

namespace {
    use Andrea\Helpdesk\Notifications\NotificationService;
    use Andrea\Helpdesk\Notifications\AutoResponder;
    use Andrea\Helpdesk\Notifications\EmailNotifier;
    use Andrea\Helpdesk\Notifications\SlackNotifier;
    use Andrea\Helpdesk\Notifications\AgentNotificationRepository;
    use Andrea\Helpdesk\Notifications\PushNotificationService;

    require_once __DIR__ . '/../src/Notifications/NotificationService.php';

    $ticket = ['id' => 1, 'ticket_number' => 'TEST-1', 'subject' => 'Billing receipt', 'suppress_emails' => 0];
    $customer = ['email' => 'billing@example.test', 'suppress_emails' => 0];
    $service = new NotificationService();
    $cases = [
        ['Automated email', true, 0, 0, 0],
        ['Ordinary email after automated email', false, 0, 0, 1],
        ['Existing ticket suppression', false, 1, 0, 0],
        ['Existing customer suppression', false, 0, 1, 0],
    ];
    foreach ($cases as [$label, $suppress, $ticketSuppressed, $customerSuppressed, $expectedAuto]) {
        AutoResponder::$sent = EmailNotifier::$sent = SlackNotifier::$sent = 0;
        AgentNotificationRepository::$sent = PushNotificationService::$sent = 0;
        $ticket['suppress_emails'] = $ticketSuppressed;
        $customer['suppress_emails'] = $customerSuppressed;
        $service->onNewTicket($ticket, $customer, $suppress);
        if (AutoResponder::$sent !== $expectedAuto || EmailNotifier::$sent !== 1
            || SlackNotifier::$sent !== 1 || AgentNotificationRepository::$sent !== 1
            || PushNotificationService::$sent !== 1) {
            throw new RuntimeException("Failed: {$label}");
        }
        echo "PASS: {$label}; agent email, Slack, inbox and push retained\n";
    }
}
