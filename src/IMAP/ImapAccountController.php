<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\IMAP;

use Andrea\Helpdesk\Core\Request;
use Andrea\Helpdesk\Core\Response;
use Andrea\Helpdesk\Core\Database;

class ImapAccountController
{
    private ImapAccountRepository $repo;

    public function __construct()
    {
        $this->repo = new ImapAccountRepository();
    }

    public function index(Request $request): void
    {
        $accounts = $this->repo->findAll();
        // Never expose the stored password
        foreach ($accounts as &$a) {
            unset($a['password']);
        }
        Response::success($accounts);
    }

    public function store(Request $request): void
    {
        $data = $request->validate([
            'name'     => 'required',
            'host'     => 'required',
            'username' => 'required',
            'password' => 'required',
        ]);

        $data['host']                = trim($data['host']);
        $data['username']            = trim($data['username']);
        $data['port']                = $request->input('port', 993);
        $data['encryption']          = $request->input('encryption', 'ssl');
        $data['from_address']        = $request->input('from_address');
        $data['folder']              = $request->input('folder', 'INBOX');
        $data['delete_after_import'] = $request->input('delete_after_import', false);
        $data['tag_id']              = $request->input('tag_id');
        $data['is_enabled']          = $request->input('is_enabled', true);

        $id      = $this->repo->create($data);
        $account = $this->repo->findById($id);
        unset($account['password']);
        Response::created($account, 'IMAP account created');
    }

    public function update(Request $request, array $params): void
    {
        $id = (int)$params['id'];
        if (!$this->repo->findById($id)) {
            Response::error('IMAP account not found', 404);
            return;
        }

        $fields = ['name', 'host', 'port', 'encryption', 'username', 'from_address', 'folder',
                   'delete_after_import', 'tag_id', 'is_enabled'];
        $data   = [];
        foreach ($fields as $field) {
            if ($request->input($field) !== null) {
                $data[$field] = $request->input($field);
            }
        }
        if (isset($data['host']))     $data['host']     = trim($data['host']);
        if (isset($data['username'])) $data['username'] = trim($data['username']);
        // Only update password if provided
        $password = $request->input('password');
        if ($password) {
            $data['password'] = $password;
        }

        $this->repo->update($id, $data);
        $account = $this->repo->findById($id);
        unset($account['password']);
        Response::success($account, 'IMAP account updated');
    }

    public function destroy(Request $request, array $params): void
    {
        $id = (int)$params['id'];
        if (!$this->repo->findById($id)) {
            Response::error('IMAP account not found', 404);
            return;
        }
        $this->repo->delete($id);
        Response::success(null, 'IMAP account deleted');
    }

    public function pollNow(Request $request, array $params): void
    {
        $id      = (int)$params['id'];
        $account = $this->repo->findById($id);
        if (!$account) {
            Response::error('IMAP account not found', 404);
            return;
        }

        $config = [
            'host'                => trim($account['host']),
            'port'                => $account['port'],
            'encryption'          => $account['encryption'],
            'username'            => trim($account['username']),
            'password'            => $this->repo->getDecryptedPassword($id),
            'folder'              => $account['folder'],
            'delete_after_import' => (bool)$account['delete_after_import'],
            'tag_id'              => $account['tag_id'] ?: null,
        ];

        $poller = new ImapPoller($config, new MessageParser(), new ThreadMatcher(Database::getInstance()));

        // Suppress ImapPoller's echo output (designed for cron, not HTTP responses)
        ob_start();

        $connected = $poller->connect();

        if (!$connected) {
            ob_end_clean();
            Response::error('Failed to connect to IMAP server');
            return;
        }

        $this->repo->recordConnected($id);
        $count = $poller->poll();
        $poller->disconnect();
        ob_end_clean();
        $this->repo->recordPoll($id, $count);

        Response::success(
            ['imported' => $count],
            $count > 0 ? "Poll complete — {$count} message(s) imported." : 'Poll complete — no new messages.'
        );
    }

    public function triggerPoll(Request $request): void
    {
        $script = dirname(__DIR__, 2) . '/bin/imap-poll.php';
        if (!file_exists($script)) {
            Response::error('Poll script not found', 500);
            return;
        }
        $php = PHP_BINARY;
        exec(escapeshellarg($php) . ' ' . escapeshellarg($script) . ' > /dev/null 2>&1 &');
        Response::success(null, 'Poll triggered');
    }

    public function test(Request $request, array $params): void
    {
        $id      = (int)$params['id'];
        $account = $this->repo->findById($id);
        if (!$account) { Response::error('IMAP account not found', 404); return; }

        $result = $this->runImapCli($id, $account, 'test');
        if ($result['ok']) {
            Response::success([], $result['msg'] ?? 'Connection successful — credentials accepted.');
        } else {
            Response::error($result['msg'] ?? 'Connection failed');
        }
    }

    public function listFolders(Request $request, array $params): void
    {
        $id      = (int)$params['id'];
        $account = $this->repo->findById($id);
        if (!$account) { Response::error('IMAP account not found', 404); return; }

        $result = $this->runImapCli($id, $account, 'list');
        if (!$result['ok']) {
            Response::error($result['msg'] ?? 'Could not list folders');
            return;
        }

        $folders = $result['folders'] ?? [];
        Response::success($folders, count($folders) . ' folder(s) found');
    }

    /**
     * Run bin/imap-test.php as a CLI subprocess so that DNS resolution and
     * network access use the same context as the cron-based IMAP poller.
     * Credentials are passed via stdin (never exposed in process arguments).
     */
    private function runImapCli(int $id, array $account, string $mode): array
    {
        $script = dirname(__DIR__, 2) . '/bin/imap-test.php';
        if (!file_exists($script)) {
            return ['ok' => false, 'msg' => 'imap-test.php script not found'];
        }

        $input = json_encode([
            'host'       => trim($account['host']),
            'port'       => (int)$account['port'],
            'encryption' => strtolower($account['encryption']),
            'username'   => trim($account['username']),
            'password'   => $this->repo->getDecryptedPassword($id),
            'mode'       => $mode,
        ]);

        // PHP_BINARY is unreliable in the Apache/mod_php context; find a real CLI binary.
        $phpBin = $this->findPhpBinary();

        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(escapeshellarg($phpBin) . ' ' . escapeshellarg($script), $desc, $pipes);
        if (!is_resource($proc)) {
            return ['ok' => false, 'msg' => 'Could not start test subprocess'];
        }

        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return json_decode($output ?: '{}', true) ?? ['ok' => false, 'msg' => 'No output from test subprocess'];
    }

    private function findPhpBinary(): string
    {
        // PHP_BINARY is the Apache/FPM process itself in web context, not the CLI binary.
        // Try common locations in order.
        $candidates = [
            PHP_BINARY,
            '/usr/bin/php',
            '/usr/bin/php8.4',
            '/usr/bin/php8.3',
            '/usr/bin/php8.2',
            '/usr/bin/php8.1',
            '/usr/local/bin/php',
        ];
        foreach ($candidates as $bin) {
            if ($bin && is_executable($bin) && !str_contains($bin, 'apache') && !str_contains($bin, 'fpm')) {
                return $bin;
            }
        }
        return '/usr/bin/php';
    }
}
