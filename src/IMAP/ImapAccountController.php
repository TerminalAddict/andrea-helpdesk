<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\IMAP;

use Andrea\Helpdesk\Core\Request;
use Andrea\Helpdesk\Core\Response;

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

        // TCP connect first (works regardless of TLS)
        $sock = @fsockopen($host, $port, $errno, $errstr, 15);
        if (!$sock) {
            Response::error("Cannot connect to {$host}:{$port} — {$errstr}");
            return;
        }
        stream_set_timeout($sock, 15);

        // Upgrade to TLS immediately for implicit SSL (port 993)
        if ($encryption === 'ssl') {
            stream_context_set_option($sock, 'ssl', 'verify_peer',      false);
            stream_context_set_option($sock, 'ssl', 'verify_peer_name', false);
            $tlsOk = @stream_socket_enable_crypto($sock, true,
                STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
            if (!$tlsOk) {
                $err = error_get_last();
                fclose($sock);
                Response::error('TLS handshake failed — ' . ($err['message'] ?? 'check server SSL/TLS configuration'));
                return;
            }
        }

        $greeting = fgets($sock, 1024);
        if (!$greeting || strpos($greeting, '* OK') === false) {
            fclose($sock);
            Response::error('Connected but did not receive IMAP greeting: ' . trim($greeting ?: '(no response)'));
            return;
        }

        // STARTTLS upgrade for explicit TLS (port 143)
        if ($encryption === 'tls') {
            fwrite($sock, "A1 STARTTLS\r\n");
            $resp = fgets($sock, 1024);
            if (!$resp || strpos($resp, 'A1 OK') === false) {
                fclose($sock);
                Response::error('STARTTLS not supported: ' . trim($resp ?: '(no response)'));
                return;
            }
            stream_context_set_option($sock, 'ssl', 'verify_peer',      false);
            stream_context_set_option($sock, 'ssl', 'verify_peer_name', false);
            if (!@stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($sock);
                Response::error('STARTTLS upgrade failed');
                return;
            }
        }

        fwrite($sock, "A2 LOGIN \"{$username}\" \"{$password}\"\r\n");
        $resp = fgets($sock, 1024);

        fwrite($sock, "A3 LOGOUT\r\n");
        fclose($sock);

        if (strpos($resp, 'A2 OK') !== false) {
            Response::success([], 'Connection successful — credentials accepted.');
        } elseif (strpos($resp, 'A2 NO') !== false || strpos($resp, 'A2 BAD') !== false) {
            Response::error('Connected but login failed — check username/password. Server: ' . trim($resp));
        } else {
            Response::error('Unexpected server response: ' . trim($resp ?: '(no response)'));
        }
    }
}
