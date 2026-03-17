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

        $scheme  = match($encryption) {
            'ssl'  => 'imaps',
            'tls'  => 'imap',
            default => 'imap',
        };
        $url = "{$scheme}://{$host}:{$port}/";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_USERNAME       => $username,
            CURLOPT_PASSWORD       => $password,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USE_SSL        => $encryption === 'tls' ? CURLUSESSL_ALL : CURLUSESSL_NONE,
        ]);

        curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        // curl IMAP error codes: 67 = bad credentials, 0 = success, 7 = can't connect
        if ($curlErrno === 0 || $curlErrno === 78) {
            // 0 = listed mailbox OK, 78 = remote file not found (valid login, folder may be empty)
            Response::success([], 'Connection successful — credentials accepted.');
        } elseif ($curlErrno === 67) {
            Response::error('Connected but login failed — check username/password.');
        } elseif ($curlErrno === 7) {
            Response::error("Cannot connect to {$host}:{$port} — server unreachable.");
        } else {
            Response::error("Connection failed (curl #{$curlErrno}): {$curlError}");
        }
    }
}
