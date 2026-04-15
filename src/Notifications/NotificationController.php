<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Notifications;

use Andrea\Helpdesk\Core\Request;
use Andrea\Helpdesk\Core\Response;
use Andrea\Helpdesk\Core\VersionService;

class NotificationController
{
    private AgentNotificationRepository $repo;
    private UpdateCheckService $updateChecks;
    private VersionService $versions;

    public function __construct()
    {
        $this->repo         = new AgentNotificationRepository();
        $this->updateChecks = new UpdateCheckService();
        $this->versions     = new VersionService();
    }

    public function index(Request $request): void
    {
        $agentId = (int)$request->agent->id;
        $limit   = max(1, min(50, (int)$request->input('limit', 12)));
        $afterId = (int)$request->input('after_id', 0);

        $items = $this->repo->listActiveTicketNotificationsForAgent($agentId, max($limit, 50), $afterId > 0 ? $afterId : null, true);
        $updateItem = $this->activeUpdateNotification($agentId, true, $afterId > 0 ? $afterId : null);
        if ($updateItem) {
            $items[] = $updateItem;
        }

        $items = $this->normaliseItems($items, $limit, $afterId > 0, true);

        Response::success([
            'items'        => $items,
            'unread_count' => $this->activeUnreadCount($agentId),
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
            'unread_count' => $this->activeUnreadCount($agentId),
            'active_count' => $this->activeCount($agentId),
        ]);
    }

    public function markRead(Request $request, array $params): void
    {
        $agentId = (int)$request->agent->id;
        $this->repo->markRead($agentId, (int)$params['id']);
        Response::success([
            'unread_count' => $this->activeUnreadCount($agentId),
            'active_count' => $this->activeCount($agentId),
        ], 'Notification updated');
    }

    public function markAllRead(Request $request): void
    {
        $agentId = (int)$request->agent->id;
        $this->repo->markAllRead($agentId);
        Response::success([
            'unread_count' => 0,
            'active_count' => $this->activeCount($agentId),
        ], 'Notifications marked read');
    }

    public function checkUpdates(Request $request): void
    {
        $force = in_array((string)$request->input('force', '0'), ['1', 'true', 'yes'], true);
        Response::success($this->updateChecks->checkForAgent((int)$request->agent->id, $force));
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
            'ticket_overdue' => 'ticket_overdue:' . $ticketId,
            'sla_escalated' => 'sla_escalated:' . $ticketId,
            default => $forMenu ? null : $type . ':' . $ticketId,
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

    private function activeUnreadCount(int $agentId): int
    {
        $items = $this->repo->listActiveTicketNotificationsForAgent($agentId, 250, null, true);
        $updateItem = $this->activeUpdateNotification($agentId, true);
        if ($updateItem) {
            $items[] = $updateItem;
        }
        return count($this->normaliseItems($items, 1000, false, true));
    }

    private function activeUpdateNotification(int $agentId, bool $unreadOnly = false, ?int $afterId = null): ?array
    {
        $item = $this->repo->latestUpdateNotificationForAgent($agentId, $unreadOnly, $afterId);
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
