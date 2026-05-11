<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Notifications;

use Andrea\Helpdesk\Core\Request;
use Andrea\Helpdesk\Core\Response;
use Andrea\Helpdesk\Core\VersionService;
use Andrea\Helpdesk\Agents\AgentRepository;

class NotificationController
{
    private AgentNotificationRepository $repo;
    private UpdateCheckService $updateChecks;
    private VersionService $versions;
    private AgentRepository $agents;

    public function __construct()
    {
        $this->repo         = new AgentNotificationRepository();
        $this->updateChecks = new UpdateCheckService();
        $this->versions     = new VersionService();
        $this->agents       = new AgentRepository();
    }

    public function index(Request $request): void
    {
        $agentId = (int)$request->agent->id;
        $limit   = max(1, min(50, (int)$request->input('limit', 12)));
        $afterId = (int)$request->input('after_id', 0);

        $items = $this->repo->listActiveTicketNotificationsForAgent($agentId, max($limit, 50), $afterId > 0 ? $afterId : null);
        $updateItem = $this->activeUpdateNotification($agentId, $afterId > 0 ? $afterId : null);
        if ($updateItem) {
            $items[] = $updateItem;
        }

        $items = $this->normaliseItems($items, $limit, $afterId > 0, true);

        Response::success([
            'items'        => $items,
            'active_count' => $this->activeCount($agentId),
        ]);
    }

    public function active(Request $request): void
    {
        $agentId = (int)$request->agent->id;
        $limit   = max(1, min(250, (int)$request->input('limit', 100)));

        $items = $this->repo->listActiveTicketNotificationsForAgent($agentId, $limit);
        $updateItem = $this->activeUpdateNotification($agentId);
        if ($updateItem) {
            $items[] = $updateItem;
        }

        Response::success([
            'items'        => $this->normaliseItems($items, $limit, false, false),
            'active_count' => $this->activeCount($agentId),
        ]);
    }

    public function delete(Request $request, array $params): void
    {
        $agentId = (int)$request->agent->id;
        $this->repo->deleteForAgent($agentId, (int)$params['id']);
        Response::success([
            'active_count' => $this->activeCount($agentId),
        ], 'Notification updated');
    }

    public function dismissOpenedTicket(Request $request, array $params): void
    {
        $agentId = (int)$request->agent->id;
        $this->repo->deleteOpenedTicketNotifications($agentId, (int)$params['id']);
        Response::success([
            'active_count' => $this->activeCount($agentId),
        ], 'Ticket notifications dismissed');
    }

    public function checkUpdates(Request $request): void
    {
        $force = in_array((string)$request->input('force', '0'), ['1', 'true', 'yes'], true);
        Response::success($this->updateChecks->checkForAgent((int)$request->agent->id, $force));
    }

    public function preferences(Request $request): void
    {
        $agent = $this->agents->findById((int)$request->agent->id);
        Response::success([
            'preferences' => NotificationService::normalisePreferences(
                $agent['notification_preferences_json'] ?? null,
                (string)($agent['role'] ?? 'agent')
            ),
            'browser_notifications_enabled' => !empty($agent['browser_notifications_enabled']),
        ]);
    }

    public function updatePreferences(Request $request): void
    {
        $agent = $this->agents->findById((int)$request->agent->id);
        $incoming = $request->input('preferences', []);
        $prefs = NotificationService::normalisePreferences(
            is_array($incoming) ? $incoming : [],
            (string)($agent['role'] ?? 'agent')
        );

        $this->agents->update((int)$request->agent->id, [
            'notification_preferences_json' => json_encode($prefs),
        ]);

        Response::success(['preferences' => $prefs], 'Notification preferences saved');
    }

    private function normaliseItems(array $items, int $limit, bool $ascending, bool $forMenu): array
    {
        foreach ($items as &$item) {
            $item['data'] = !empty($item['data_json']) ? (json_decode((string)$item['data_json'], true) ?: []) : [];
            unset($item['data_json']);
        }
        unset($item);

        $items = $this->collapseActiveIssues($items, $forMenu);

        usort($items, static function (array $left, array $right) use ($ascending): int {
            $cmp = ((int)$left['id']) <=> ((int)$right['id']);
            return $ascending ? $cmp : -$cmp;
        });

        return array_slice($items, 0, $limit);
    }

    private function collapseActiveIssues(array $items, bool $forMenu): array
    {
        $collapsed = [];
        foreach ($items as $item) {
            $key = $this->activeIssueKey($item, $forMenu);
            if ($key === null) {
                $collapsed[] = $item;
                continue;
            }

            if (!isset($collapsed[$key]) || (int)$item['id'] > (int)$collapsed[$key]['id']) {
                $collapsed[$key] = $item;
            }
        }

        return array_values($collapsed);
    }

    private function activeIssueKey(array $item, bool $forMenu): ?string
    {
        $type = (string)($item['type'] ?? '');
        $data = $item['data'] ?? [];
        $ticketId = (int)($data['ticket_id'] ?? 0);

        if ($type === 'update_available') {
            return 'update:' . (string)($data['latest_version'] ?? '');
        }

        if ($ticketId <= 0) {
            return null;
        }

        return match ($type) {
            'ticket_sla_overdue' => 'ticket_sla_overdue:' . $ticketId,
            'ticket_due_overdue' => 'ticket_due_overdue:' . $ticketId,
            default => $type . ':' . $ticketId,
        };
    }

    private function activeCount(int $agentId): int
    {
        $items = $this->repo->listActiveTicketNotificationsForAgent($agentId, 250);
        $updateItem = $this->activeUpdateNotification($agentId);
        if ($updateItem) {
            $items[] = $updateItem;
        }
        return count($this->normaliseItems($items, 1000, false, false));
    }

    private function activeUpdateNotification(int $agentId, ?int $afterId = null): ?array
    {
        $agent = $this->agents->findById($agentId);
        if (!$agent || !(new NotificationService())->agentWantsNotification($agent, 'update_available')) {
            return null;
        }

        $item = $this->repo->latestUpdateNotificationForAgent($agentId, $afterId);
        if (!$item) {
            return null;
        }

        $data = !empty($item['data_json']) ? (json_decode((string)$item['data_json'], true) ?: []) : [];
        $latestVersion = (string)($data['latest_version'] ?? '');
        $installedVersion = (string)(($this->versions->getInstalled())['version'] ?? '0.0.0');

        return ($latestVersion !== '' && $this->versions->compare($latestVersion, $installedVersion) > 0)
            ? $item
            : null;
    }
}
