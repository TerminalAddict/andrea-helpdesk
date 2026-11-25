<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Core {
    class Database
    {
        public array $writes = [];
        public function fetchAll(...$args): array
        {
            return [['channel_id' => 1, 'retention_days' => 90, 'message_count' => 2]];
        }
        public function fetch(...$args): array { return ['retention_days' => 90, 'message_count' => 3]; }
        public function execute(string $sql, array $params): void { $this->writes[] = [$sql, $params]; }
    }
}
namespace Andrea\Helpdesk\Settings {
    class SettingsService
    {
        public function getChatConfig(): array
        {
            return ['default_channel_retention_days' => 90, 'direct_retention_days' => 90];
        }
    }
}
namespace {
    require_once __DIR__ . '/../src/Chat/ChatService.php';
    foreach ([0, 7] as $actor) {
        $reflection = new ReflectionClass(Andrea\Helpdesk\Chat\ChatService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $db = new Andrea\Helpdesk\Core\Database();
        $reflection->getProperty('db')->setValue($service, $db);
        $reflection->getProperty('settings')->setValue($service, new Andrea\Helpdesk\Settings\SettingsService());
        $service->prune(null, null, $actor, $actor ? '127.0.0.1' : 'cli');
        $expected = $actor ?: null;
        if (count($db->writes) !== 3 || $db->writes[0][1][0] !== $expected
            || $db->writes[1][1][0] !== $expected
            || $db->writes[2][1][0] !== ($actor ? 'agent' : 'system')
            || $db->writes[2][1][1] !== $expected) {
            throw new RuntimeException('Incorrect prune or audit actor');
        }
        echo "PASS: prune actor {$actor} for channel, direct, and audit records\n";
    }
}
