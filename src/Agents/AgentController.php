<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Agents;

use Andrea\Helpdesk\Auth\PasswordService;
use Andrea\Helpdesk\Core\Exceptions\HttpException;
use Andrea\Helpdesk\Core\Request;
use Andrea\Helpdesk\Core\Response;
use Andrea\Helpdesk\Core\Exceptions\NotFoundException;

class AgentController
{
    private AgentRepository $repo;
    private AgentService $service;

    public function __construct()
    {
        $this->repo    = new AgentRepository();
        $this->service = new AgentService($this->repo);
    }

    private function sanitise(?array $agent): ?array
    {
        if (!$agent) return null;
        unset($agent['password_hash']);
        return $agent;
    }

    public function index(Request $request): void
    {
        $includeInactive = $request->input('include_inactive') === '1';
        $agents = array_map([$this, 'sanitise'], $this->repo->findAll($includeInactive));
        Response::success($agents);
    }

    public function show(Request $request, array $params): void
    {
        $agent = $this->repo->findById((int)$params['id']);
        if (!$agent) throw new NotFoundException('Agent not found');
        Response::success($this->sanitise($agent));
    }

    public function store(Request $request): void
    {
        $data = $request->validate([
            'name'     => 'required',
            'email'    => 'required|email',
            'password' => 'required|min:8',
        ]);

        $data['role']               = $request->input('role', 'agent');
        $data['can_close_tickets']  = $request->input('can_close_tickets', true);
        $data['can_delete_tickets'] = $request->input('can_delete_tickets', false);
        $data['can_edit_customers'] = $request->input('can_edit_customers', false);
        $data['can_view_reports']   = $request->input('can_view_reports', false);
        $data['can_manage_kb']      = $request->input('can_manage_kb', false);
        $data['can_manage_tags']    = $request->input('can_manage_tags', false);
        $data['signature']          = $request->input('signature');

        $agent = $this->service->create($data);
        Response::created($agent, 'Agent created');
    }

    public function update(Request $request, array $params): void
    {
        $agent = $this->repo->findById((int)$params['id']);
        if (!$agent) throw new NotFoundException('Agent not found');

        $data = [];
        foreach (['name', 'email', 'role', 'password', 'can_close_tickets', 'can_delete_tickets',
                  'can_edit_customers', 'can_view_reports', 'can_manage_kb', 'can_manage_tags', 'signature', 'is_active'] as $field) {
            if ($request->input($field) !== null) {
                $data[$field] = $request->input($field);
            }
        }

        $updated = $this->service->update($agent['id'], $data);
        Response::success($updated, 'Agent updated');
    }

    public function deactivate(Request $request, array $params): void
    {
        $agent = $this->repo->findById((int)$params['id']);
        if (!$agent) throw new NotFoundException('Agent not found');
        $this->repo->deactivate($agent['id']);
        Response::success(null, 'Agent deactivated');
    }

    public function activate(Request $request, array $params): void
    {
        $agent = $this->repo->findById((int)$params['id']);
        if (!$agent) throw new NotFoundException('Agent not found');
        $this->repo->activate($agent['id']);
        Response::success(null, 'Agent activated');
    }

    public function updateProfile(Request $request): void
    {
        $agentId   = $request->agent->id;
        $agent     = $this->repo->findById($agentId);
        if (!$agent) throw new NotFoundException('Agent not found');

        $data = [];

        // Signature update
        if ($request->input('signature') !== null) {
            $data['signature'] = $request->input('signature');
        }

        // Password change — requires current password
        $newPassword = $request->input('new_password');
        if ($newPassword !== null && $newPassword !== '') {
            $currentPassword = $request->input('current_password', '');
            $passwords       = new PasswordService();
            if (!$passwords->verify($currentPassword, $agent['password_hash'])) {
                throw new HttpException('Current password is incorrect', 422);
            }
            if (!$passwords->meetsRequirements($newPassword)) {
                throw new HttpException('New password must be at least 8 characters', 422);
            }
            $data['password_hash'] = $passwords->hash($newPassword);
        }

        if (!empty($data)) {
            $this->repo->update($agentId, $data);
        }

        $updated = $this->repo->findById($agentId);
        unset($updated['password_hash']);
        Response::success($updated, 'Profile updated');
    }

    public function resetPassword(Request $request, array $params): void
    {
        $agent = $this->repo->findById((int)$params['id']);
        if (!$agent) throw new NotFoundException('Agent not found');

        $newPassword = $this->service->resetPassword($agent['id']);
        Response::success(['new_password' => $newPassword], 'Password reset. Share this password securely.');
    }
}
