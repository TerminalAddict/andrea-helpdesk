<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Customers;

use Andrea\Helpdesk\Core\Request;
use Andrea\Helpdesk\Core\Response;
use Andrea\Helpdesk\Core\Exceptions\NotFoundException;
use Andrea\Helpdesk\Core\Exceptions\HttpException;

class CustomerController
{
    private CustomerRepository $repo;
    private CustomerService $service;

    public function __construct()
    {
        $this->repo    = new CustomerRepository();
        $this->service = new CustomerService($this->repo);
    }

    private function sanitise(array $customer): array
    {
        unset($customer['portal_password_hash'], $customer['portal_token'], $customer['portal_token_expires']);
        return $customer;
    }

    public function index(Request $request): void
    {
        $page    = max(1, (int)$request->input('page', 1));
        $perPage = min(100, max(1, (int)$request->input('per_page', 25)));
        $filters = ['q' => $request->input('q'), 'company' => $request->input('company')];
        $result  = $this->repo->findAll(array_filter($filters), $page, $perPage);

        $result['items'] = array_map([$this, 'sanitise'], $result['items']);
        Response::paginated($result['items'], $result['total'], $page, $perPage);
    }

    public function show(Request $request, array $params): void
    {
        $customer = $this->repo->findById((int)$params['id']);
        if (!$customer) throw new NotFoundException('Customer not found');
        Response::success($this->sanitise($customer));
    }

    public function store(Request $request): void
    {
        // Requires can_edit_customers or admin (enforced in middleware)
        $data = $request->validate([
            'name'  => 'required',
            'email' => 'required|email',
        ]);
        $data['phone']   = $request->input('phone');
        $data['company'] = $request->input('company');
        $data['notes']   = $request->input('notes');

        $existing = $this->repo->findByEmail($data['email']);
        if ($existing) {
            throw new HttpException('A customer with this email already exists', 409);
        }

        $id       = $this->repo->create($data);
        $customer = $this->repo->findById($id);
        Response::created($this->sanitise($customer), 'Customer created');
    }

    public function update(Request $request, array $params): void
    {
        $customer = $this->repo->findById((int)$params['id']);
        if (!$customer) throw new NotFoundException('Customer not found');

        $allowed = ['name', 'email', 'phone', 'company', 'notes'];
        $data    = [];
        foreach ($allowed as $field) {
            if ($request->input($field) !== null) {
                $data[$field] = $request->input($field);
            }
        }

        $this->repo->update($customer['id'], $data);
        Response::success($this->sanitise($this->repo->findById($customer['id'])), 'Customer updated');
    }

    public function destroy(Request $request, array $params): void
    {
        $customer = $this->repo->findById((int)$params['id']);
        if (!$customer) throw new NotFoundException('Customer not found');
        $this->repo->softDelete($customer['id']);
        Response::success(null, 'Customer deleted');
    }

    public function tickets(Request $request, array $params): void
    {
        $customer = $this->repo->findById((int)$params['id']);
        if (!$customer) throw new NotFoundException('Customer not found');

        $page    = max(1, (int)$request->input('page', 1));
        $perPage = max(1, min(100, (int)$request->input('per_page', 25)));
        $result  = $this->repo->getTickets($customer['id'], $page, $perPage);
        Response::paginated($result['items'], $result['total'], $page, $perPage);
    }

    public function setPassword(Request $request, array $params): void
    {
        $customer = $this->repo->findById((int)$params['id']);
        if (!$customer) throw new NotFoundException('Customer not found');

        $data = $request->validate([
            'password'         => 'required|min:8',
            'password_confirm' => 'required',
        ]);

        if ($data['password'] !== $data['password_confirm']) {
            throw new \Andrea\Helpdesk\Core\Exceptions\ValidationException(['password_confirm' => ['Passwords do not match']]);
        }

        $this->service->setPortalPassword($customer['id'], $data['password']);
        Response::success(null, 'Customer password updated');
    }

    public function portalInvite(Request $request, array $params): void
    {
        $customer = $this->repo->findById((int)$params['id']);
        if (!$customer) throw new NotFoundException('Customer not found');

        $success = $this->service->sendPortalInvite($customer['id']);
        if ($success) {
            Response::success(null, 'Portal invite sent');
        } else {
            Response::error('Failed to send portal invite');
        }
    }
}
