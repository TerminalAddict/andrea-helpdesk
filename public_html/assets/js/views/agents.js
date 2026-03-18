/**
 * Admin: Agents Management View
 */
const AgentsView = {
    agents: [],

    render() {
        return `
        <div class="container-fluid p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4 class="mb-0"><i class="bi bi-people-fill me-2"></i>Agents</h4>
                <button class="btn btn-primary btn-sm" id="btn-new-agent">
                    <i class="bi bi-plus-lg me-1"></i>Add Agent
                </button>
            </div>
            <div id="agents-table-wrap" class="card border-0 shadow-sm">
                <div class="card-body p-0">
                    <div class="text-center py-5 text-muted">
                        <div class="spinner-border"></div><p class="mt-2">Loading…</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Agent Modal -->
        <div class="modal fade" id="agentModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="agent-modal-title">Add Agent</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div id="agent-modal-error" class="alert alert-danger d-none"></div>
                        <form id="agent-form">
                            <input type="hidden" id="agent-id">
                            <div class="mb-3">
                                <label class="form-label">Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="agent-name" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" id="agent-email" required autocomplete="username">
                            </div>
                            <div class="mb-3" id="agent-password-wrap">
                                <label class="form-label">Password <span class="text-danger" id="pw-required">*</span></label>
                                <input type="password" class="form-control" id="agent-password" placeholder="Leave blank to keep unchanged" autocomplete="new-password">
                                <div class="form-text" id="pw-hint" style="display:none;">Leave blank to keep existing password</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Role</label>
                                <select class="form-select" id="agent-role">
                                    <option value="agent">Agent</option>
                                    <option value="admin">Admin</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Permissions</label>
                                ${[
                                    ['can_close_tickets',  'Close / Resolve Tickets'],
                                    ['can_delete_tickets', 'Delete Tickets'],
                                    ['can_edit_customers', 'Edit Customer Records'],
                                    ['can_view_reports',   'View Reports'],
                                    ['can_manage_kb',      'Manage KB Categories'],
                                    ['can_manage_tags',    'Manage Tags'],
                                ].map(([key, label]) =>
                                    `<div class="form-check">
                                        <input class="form-check-input agent-perm" type="checkbox" id="perm-${key}" value="${key}">
                                        <label class="form-check-label" for="perm-${key}">${label}</label>
                                    </div>`
                                ).join('')}
                                <div class="form-text">Admins automatically have all permissions.</div>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="btn-save-agent">
                            <span class="spinner-border spinner-border-sm d-none me-1" id="agent-save-spinner"></span>
                            Save
                        </button>
                    </div>
                </div>
            </div>
        </div>`;
    },

    async init() {
        await this.loadAgents();
        this.bindEvents();
    },

    async loadAgents() {
        try {
            const res = await API.get('/agents');
            this.agents = res.data || [];
            this.renderTable();
        } catch (e) {
            $('#agents-table-wrap').html('<p class="text-danger p-3">' + App.escapeHtml(e.message) + '</p>');
        }
    },

    renderTable() {
        if (!this.agents.length) {
            $('#agents-table-wrap .card-body').html('<p class="text-muted p-4 text-center">No agents found.</p>');
            return;
        }

        const rows = this.agents.map(a => {
            const perms = ['can_close_tickets','can_delete_tickets','can_edit_customers','can_view_reports','can_manage_kb','can_manage_tags']
                .filter(p => a[p])
                .map(p => `<span class="badge bg-light text-dark border me-1 small">${p.replace('can_','')}</span>`)
                .join('');
            return `
            <tr>
                <td>
                    <div class="fw-semibold">${App.escapeHtml(a.name)}</div>
                    <div class="small text-muted">${App.escapeHtml(a.email)}</div>
                </td>
                <td>
                    <span class="badge ${a.role === 'admin' ? 'bg-danger' : 'bg-primary'}">${a.role}</span>
                </td>
                <td class="small">${perms || '<span class="text-muted">–</span>'}</td>
                <td>
                    <span class="badge ${a.is_active ? 'bg-success' : 'bg-secondary'}">${a.is_active ? 'Active' : 'Inactive'}</span>
                </td>
                <td>
                    <button class="btn btn-sm btn-outline-secondary btn-edit-agent me-1" data-id="${a.id}">Edit</button>
                    ${a.id !== (API.currentUser && API.currentUser.id) ?
                        `<button class="btn btn-sm btn-outline-${a.is_active ? 'warning' : 'success'} btn-toggle-agent" data-id="${a.id}" data-active="${a.is_active ? 1 : 0}">
                            ${a.is_active ? 'Deactivate' : 'Activate'}
                        </button>` : ''}
                </td>
            </tr>`;
        }).join('');

        $('#agents-table-wrap').html(`
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>Agent</th><th>Role</th><th>Permissions</th><th>Status</th><th style="width:160px;"></th></tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>`);

        $('.btn-edit-agent').on('click', (e) => this.openEditModal($(e.currentTarget).data('id')));
        $('.btn-toggle-agent').on('click', (e) => this.toggleAgent($(e.currentTarget).data('id'), $(e.currentTarget).data('active')));
    },

    bindEvents() {
        $('#btn-new-agent').on('click', () => this.openNewModal());
        $('#btn-save-agent').on('click', () => this.saveAgent());
    },

    openNewModal() {
        $('#agent-modal-title').text('Add Agent');
        $('#agent-form')[0].reset();
        $('#agent-id').val('');
        $('#pw-required').show();
        $('#pw-hint').hide();
        $('#agent-modal-error').addClass('d-none');
        const modal = new bootstrap.Modal(document.getElementById('agentModal'));
        document.getElementById('agentModal').addEventListener('hide.bs.modal', () => {
            if (document.activeElement) document.activeElement.blur();
        }, { once: true });
        modal.show();
    },

    openEditModal(id) {
        const agent = this.agents.find(a => a.id == id);
        if (!agent) return;

        $('#agent-modal-title').text('Edit Agent');
        $('#agent-id').val(agent.id);
        $('#agent-name').val(agent.name);
        $('#agent-email').val(agent.email);
        $('#agent-password').val('');
        $('#agent-role').val(agent.role);
        $('#pw-required').hide();
        $('#pw-hint').show();
        $('#agent-modal-error').addClass('d-none');

        $('.agent-perm').each(function() {
            $(this).prop('checked', !!agent[$(this).val()]);
        });

        const modal = new bootstrap.Modal(document.getElementById('agentModal'));
        document.getElementById('agentModal').addEventListener('hide.bs.modal', () => {
            if (document.activeElement) document.activeElement.blur();
        }, { once: true });
        modal.show();
    },

    async saveAgent() {
        const id       = $('#agent-id').val();
        const name     = $('#agent-name').val().trim();
        const email    = $('#agent-email').val().trim();
        const password = $('#agent-password').val();
        const role     = $('#agent-role').val();

        const payload = { name, email, role };
        if (password) payload.password = password;

        $('.agent-perm').each(function() {
            payload[$(this).val()] = this.checked;
        });

        $('#agent-save-spinner').removeClass('d-none');
        $('#btn-save-agent').prop('disabled', true);
        $('#agent-modal-error').addClass('d-none');

        try {
            if (id) {
                await API.put('/agents/' + id, payload);
            } else {
                if (!password) { throw new Error('Password is required for new agents'); }
                await API.post('/agents', payload);
            }
            bootstrap.Modal.getInstance(document.getElementById('agentModal')).hide();
            App.toast('Agent saved');
            await this.loadAgents();
        } catch (e) {
            $('#agent-modal-error').text(e.message).removeClass('d-none');
        } finally {
            $('#agent-save-spinner').addClass('d-none');
            $('#btn-save-agent').prop('disabled', false);
        }
    },

    async toggleAgent(id, currentlyActive) {
        const action = currentlyActive ? 'deactivate' : 'activate';
        const confirmed = await App.confirm(`${currentlyActive ? 'Deactivate' : 'Activate'} this agent?`);
        if (!confirmed) return;
        try {
            await API.put('/agents/' + id, { is_active: !currentlyActive });
            App.toast('Agent ' + action + 'd');
            await this.loadAgents();
        } catch (e) { App.toast(e.message, 'error'); }
    }
};
