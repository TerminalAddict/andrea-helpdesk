<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Notifications;

use Andrea\Helpdesk\Agents\AgentRepository;
use Andrea\Helpdesk\Core\Database;
use Andrea\Helpdesk\Core\VersionService;

class UpdateCheckService
{
    private const CHECK_INTERVAL_SECONDS = 86400;

    private AgentRepository $agents;
    private Database $db;
    private VersionService $versions;
    private NotificationService $notifications;

    public function __construct()
    {
        $this->db             = Database::getInstance();
        $this->agents         = new AgentRepository();
        $this->versions       = new VersionService();
        $this->notifications  = new NotificationService();
    }

    public function shouldCheck(array $agent): bool
    {
        if (($agent['role'] ?? '') !== 'admin') {
            return false;
        }

        if (empty($agent['last_update_check_at'])) {
            return true;
        }

        return (time() - strtotime((string)$agent['last_update_check_at'])) >= self::CHECK_INTERVAL_SECONDS;
    }

    public function checkForAgent(int $agentId, bool $force = false): array
    {
        $agent = $this->agents->findById($agentId);
        if (!$agent || (!$force && !$this->shouldCheck($agent))) {
            return ['checked' => false, 'created' => false];
        }

        $lockName = 'andrea-helpdesk:update-check:' . $agentId;
        if (!$this->acquireLock($lockName)) {
            return ['checked' => false, 'created' => false];
        }

        try {
            $agent = $this->agents->findById($agentId);
            if (!$agent || (!$force && !$this->shouldCheck($agent))) {
                return ['checked' => false, 'created' => false];
            }

            $installed = $this->versions->getInstalled();
            $latest    = $this->versions->getLatest();

            $this->agents->update($agentId, ['last_update_check_at' => date('Y-m-d H:i:s')]);

            $installedVersion = (string)($installed['version'] ?? '0.0.0');
            $latestVersion    = (string)($latest['version'] ?? '0.0.0');
            $created          = false;

            if ($this->versions->compare($latestVersion, $installedVersion) > 0) {
                $this->notifications->onUpdateAvailable($agentId, $installed, $latest);
                $created = true;
            }

            return [
                'checked'            => true,
                'created'            => $created,
                'installed_version'  => $installedVersion,
                'latest_version'     => $latestVersion,
            ];
        } finally {
            $this->releaseLock($lockName);
        }
    }

    private function acquireLock(string $lockName): bool
    {
        $stmt = $this->db->getPdo()->prepare('SELECT GET_LOCK(?, 0)');
        $stmt->execute([$lockName]);
        return (int)$stmt->fetchColumn() === 1;
    }

    private function releaseLock(string $lockName): void
    {
        $stmt = $this->db->getPdo()->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([$lockName]);
    }
}
