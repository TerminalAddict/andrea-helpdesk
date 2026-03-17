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
            'host'                => $account['host'],
            'port'                => $account['port'],
            'encryption'          => $account['encryption'],
            'username'            => $account['username'],
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
        if (!$account) {
            Response::error('IMAP account not found', 404);
            return;
        }

        $password   = $this->repo->getDecryptedPassword($id);
        $host       = $account['host'];
        $port       = (int)$account['port'];
        $username   = $account['username'];
        $encryption = strtolower($account['encryption']);

        // imaps:// = implicit TLS (port 993); imap:// + CURLUSESSL_ALL = STARTTLS (port 143)
        if ($encryption === 'ssl') {
            $url = "imaps://{$host}:{$port}/";
        } elseif ($encryption === 'tls') {
            $url = "imap://{$host}:{$port}/";
        } else {
            $url = "imap://{$host}:{$port}/";
        }

        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_USERNAME       => $username,
            CURLOPT_PASSWORD       => $password,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_RETURNTRANSFER => true,
        ];
        // Only set USE_SSL for STARTTLS — imaps:// handles TLS via the scheme
        if ($encryption === 'tls') {
            $opts[CURLOPT_USE_SSL] = CURLUSESSL_ALL;
        }

        $ch = curl_init();
        curl_setopt_array($ch, $opts);

        curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        // 0 = success, 21 = quote/list error (authenticated but bad folder — credentials ok),
        // 67 = auth failed, 7 = can't connect, 28 = timeout
        if ($curlErrno === 0 || $curlErrno === 21 || $curlErrno === 78) {
            Response::success([], 'Connection successful — credentials accepted.');
        } elseif ($curlErrno === 67) {
            Response::error('Connected but login failed — check username/password.');
        } elseif ($curlErrno === 7) {
            Response::error("Cannot connect to {$host}:{$port} — server unreachable.");
        } elseif ($curlErrno === 28) {
            Response::error("Connection timed out to {$host}:{$port} — check host/port and firewall.");
        } else {
            Response::error("Connection failed (curl #{$curlErrno}): {$curlError}");
        }
    }
}
