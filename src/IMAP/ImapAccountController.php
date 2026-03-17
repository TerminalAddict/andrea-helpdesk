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

        $password = $this->repo->getDecryptedPassword($id);
        $encFlag  = match(strtolower($account['encryption'])) {
            'ssl'  => '/ssl',
            'tls'  => '/tls',
            default => '/notls',
        };
        $mailbox = "{{$account['host']}:{$account['port']}/imap{$encFlag}/novalidate-cert}{$account['folder']}";

        $conn = @imap_open($mailbox, $account['username'], $password, 0, 1);
        if (!$conn) {
            Response::error('Connection failed: ' . (imap_last_error() ?: 'Unknown error'));
            return;
        }
        $count = imap_num_msg($conn);
        imap_close($conn);
        Response::success(['message_count' => $count], "Connected. {$count} messages in folder.");
    }
}
