/**
 * Customer Detail View
 */
const CustomerDetailView = {
    customer: null,

    render() {
        return `
        <div class="container-fluid p-4" id="cust-detail-wrap">
            <div class="text-center py-5 text-muted">
                <div class="spinner-border"></div><p class="mt-2">Loading…</p>
            </div>
        </div>`;
    },

    async init(params) {
        try {
            const [custRes, ticketsRes] = await Promise.all([
                API.get('/customers/' + params.id),
                API.get('/customers/' + params.id + '/tickets', { per_page: 20 }),
            ]);
            this.customer = custRes.data;
            this.renderFull(ticketsRes.data || []);
        } catch (e) {
            $('#cust-detail-wrap').html('<div class="alert alert-danger m-4">' + App.escapeHtml(e.message) + '</div>');
        }
    },

    renderFull(tickets) {
        const c = this.customer;
        const canEdit = API.can('can_edit_customers') || API.isAdmin();

        const html = `
        <div class="container-fluid p-4" id="cust-detail-wrap">
            <nav aria-label="breadcrumb" class="mb-3">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="#/customers">Customers</a></li>
                    <li class="breadcrumb-item active">${App.escapeHtml(c.name || c.email)}</li>
                </ol>
            </nav>
            <div class="row g-3">
                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-header bg-white fw-semibold py-2 d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-person me-2"></i>Customer</span>
                            ${canEdit ? '<button class="btn btn-sm btn-outline-secondary py-0" id="btn-edit-cust">Edit</button>' : ''}
                        </div>
                        <div class="card-body">
                            <div id="cust-view-mode">
                                <div class="mb-2">
                                    <div class="text-muted small">Name</div>
                                    <div class="fw-semibold">${App.escapeHtml(c.name || '–')}</div>
                                </div>
                                <div class="mb-2">
                                    <div class="text-muted small">Email</div>
                                    <div>${App.escapeHtml(c.email)}</div>
                                </div>
                                <div class="mb-2">
                                    <div class="text-muted small">Phone</div>
                                    <div>${App.escapeHtml(c.phone || '–')}</div>
                                </div>
                                <div class="mb-2">
                                    <div class="text-muted small">Company</div>
                                    <div>${App.escapeHtml(c.company || '–')}</div>
                                </div>
                                <div class="mb-0">
                                    <div class="text-muted small">Customer since</div>
                                    <div class="small">${App.formatDate(c.created_at)}</div>
                                </div>
                            </div>
                            <div id="cust-edit-mode" style="display:none;">
                                <div class="mb-2">
                                    <label class="form-label small">Name</label>
                                    <input type="text" class="form-control form-control-sm" id="edit-cust-name" value="${App.escapeHtml(c.name || '')}">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small">Phone</label>
                                    <input type="text" class="form-control form-control-sm" id="edit-cust-phone" value="${App.escapeHtml(c.phone || '')}">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small">Company</label>
                                    <input type="text" class="form-control form-control-sm" id="edit-cust-company" value="${App.escapeHtml(c.company || '')}">
                                </div>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-sm btn-primary" id="btn-save-cust">Save</button>
                                    <button class="btn btn-sm btn-secondary" id="btn-cancel-edit-cust">Cancel</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white fw-semibold py-2">
                            <i class="bi bi-shield-lock me-2"></i>Portal Access
                        </div>
                        <div class="card-body">
                            <p class="small text-muted mb-2">Send a portal invitation link to this customer.</p>
                            <button class="btn btn-sm btn-outline-primary" id="btn-send-invite">
                                <i class="bi bi-envelope me-1"></i>Send Portal Invite
                            </button>
                        </div>
                    </div>
                </div>

                <div class="col-lg-8">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white fw-semibold py-2 d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-ticket-perforated me-2"></i>Tickets</span>
                            <a href="#/tickets/new?customer_email=${encodeURIComponent(c.email)}" class="btn btn-sm btn-primary">
                                <i class="bi bi-plus-lg me-1"></i>New Ticket
                            </a>
                        </div>
                        <div class="card-body p-0">
                            ${this.renderTicketsTable(tickets)}
                        </div>
                    </div>
                </div>
            </div>
        </div>`;

        $('#cust-detail-wrap').replaceWith(html);
        this.bindEvents();
    },

    renderTicketsTable(tickets) {
        if (!tickets.length) return '<p class="text-muted p-4 text-center">No tickets found.</p>';
        const rows = tickets.map(t => `
            <tr style="cursor:pointer;" onclick="App.navigate('/tickets/${t.id}')">
                <td><span class="font-monospace small">${App.escapeHtml(t.ticket_number)}</span></td>
                <td class="small">${App.escapeHtml(t.subject)}</td>
                <td>${App.statusBadge(t.status)}</td>
                <td>${App.priorityBadge(t.priority)}</td>
                <td class="small text-muted text-nowrap">${App.formatDate(t.updated_at)}</td>
            </tr>`).join('');
        return `
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>Ticket #</th><th>Subject</th><th>Status</th><th>Priority</th><th>Updated</th></tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>`;
    },

    bindEvents() {
        $('#btn-edit-cust').on('click', () => {
            $('#cust-view-mode').hide();
            $('#cust-edit-mode').show();
        });

        $('#btn-cancel-edit-cust').on('click', () => {
            $('#cust-view-mode').show();
            $('#cust-edit-mode').hide();
        });

        $('#btn-save-cust').on('click', async () => {
            try {
                await API.put('/customers/' + this.customer.id, {
                    name:    $('#edit-cust-name').val().trim(),
                    phone:   $('#edit-cust-phone').val().trim(),
                    company: $('#edit-cust-company').val().trim(),
                });
                App.toast('Customer updated');
                const res = await API.get('/customers/' + this.customer.id);
                this.customer = res.data;
                // Update view mode fields
                $('#cust-view-mode').find('.fw-semibold').first().text(this.customer.name || '–');
                $('#cust-view-mode').show();
                $('#cust-edit-mode').hide();
            } catch (e) { App.toast(e.message, 'error'); }
        });

        $('#btn-send-invite').on('click', async () => {
            try {
                await API.post('/customers/' + this.customer.id + '/portal-invite');
                App.toast('Invitation sent to ' + this.customer.email);
            } catch (e) { App.toast(e.message, 'error'); }
        });
    }
};
