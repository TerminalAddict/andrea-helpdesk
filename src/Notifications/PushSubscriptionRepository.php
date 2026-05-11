<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Notifications;

use Andrea\Helpdesk\Core\Database;

class PushSubscriptionRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function save(int $agentId, array $subscription, string $userAgent = ''): void
    {
        $endpoint = trim((string)($subscription['endpoint'] ?? ''));
        $keys = is_array($subscription['keys'] ?? null) ? $subscription['keys'] : [];
        $p256dh = trim((string)($keys['p256dh'] ?? ''));
        $auth = trim((string)($keys['auth'] ?? ''));
        $encoding = trim((string)($subscription['contentEncoding'] ?? 'aes128gcm')) ?: 'aes128gcm';

        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            throw new \InvalidArgumentException('Push subscription is missing endpoint or keys');
        }
        if (strlen($endpoint) > 2048 || !filter_var($endpoint, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Push subscription endpoint is invalid');
        }
        $scheme = strtolower((string)(parse_url($endpoint, PHP_URL_SCHEME) ?: ''));
        if ($scheme !== 'https') {
            throw new \InvalidArgumentException('Push subscription endpoint must use HTTPS');
        }
        if (strlen($p256dh) > 255 || strlen($auth) > 255) {
            throw new \InvalidArgumentException('Push subscription keys are invalid');
        }

        $this->db->execute(
            "INSERT INTO agent_push_subscriptions
                (agent_id, endpoint, endpoint_hash, p256dh, auth, content_encoding, user_agent, last_seen_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                agent_id = VALUES(agent_id),
                endpoint = VALUES(endpoint),
                p256dh = VALUES(p256dh),
                auth = VALUES(auth),
                content_encoding = VALUES(content_encoding),
                user_agent = VALUES(user_agent),
                last_seen_at = NOW()",
            [
                $agentId,
                $endpoint,
                hash('sha256', $endpoint),
                $p256dh,
                $auth,
                in_array($encoding, ['aesgcm', 'aes128gcm'], true) ? $encoding : 'aes128gcm',
                substr($userAgent, 0, 255),
            ]
        );
    }

    public function listForAgents(array $agentIds): array
    {
        $agentIds = array_values(array_unique(array_filter(array_map('intval', $agentIds), fn(int $id): bool => $id > 0)));
        if (!$agentIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($agentIds), '?'));
        return $this->db->fetchAll(
            "SELECT id, agent_id, endpoint, p256dh, auth, content_encoding
               FROM agent_push_subscriptions
              WHERE agent_id IN ({$placeholders})
           ORDER BY last_seen_at DESC",
            $agentIds
        );
    }

    public function countForAgent(int $agentId): int
    {
        return $this->db->count(
            "SELECT COUNT(*) FROM agent_push_subscriptions WHERE agent_id = ?",
            [$agentId]
        );
    }

    public function deleteForAgent(int $agentId, ?string $endpoint = null): void
    {
        if ($endpoint !== null && trim($endpoint) !== '') {
            $this->db->execute(
                "DELETE FROM agent_push_subscriptions WHERE agent_id = ? AND endpoint_hash = ?",
                [$agentId, hash('sha256', trim($endpoint))]
            );
            return;
        }

        $this->db->execute(
            "DELETE FROM agent_push_subscriptions WHERE agent_id = ?",
            [$agentId]
        );
    }

    public function deleteByEndpoint(string $endpoint): void
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return;
        }

        $this->db->execute(
            "DELETE FROM agent_push_subscriptions WHERE endpoint_hash = ?",
            [hash('sha256', $endpoint)]
        );
    }

    public function diagnostics(): array
    {
        $total = $this->db->count("SELECT COUNT(*) FROM agent_push_subscriptions");
        $agents = $this->db->count("SELECT COUNT(DISTINCT agent_id) FROM agent_push_subscriptions");
        $latest = $this->db->fetch(
            "SELECT last_seen_at FROM agent_push_subscriptions ORDER BY last_seen_at DESC LIMIT 1"
        );

        return [
            'subscription_count' => $total,
            'subscribed_agent_count' => $agents,
            'last_subscription_seen_at' => $latest['last_seen_at'] ?? null,
        ];
    }
}
