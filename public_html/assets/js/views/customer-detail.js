/**
 * Customer Detail View
 */
const CustomerDetailView = {
    customer: null,
    customerId: null,

    render() {
        return `
        <div class="container-fluid p-4" id="cust-detail-wrap">
            <div class="text-center py-5 text-muted">
                <div class="spinner-border"></div><p class="mt-2">Loading…</p>
            </div>
        </div>`;
    },

    async init(params) {
        this.customerId = params.id;
        try {
            const perPage = parseInt(API.currentUser?.page_size) || 20;
            const [custRes, ticketsRes] = await Promise.all([
                API.get('/customers/' + params.id),
                API.get('/customers/' + params.id + '/tickets', { per_page: perPage }),
            ]);
            this.customer = custRes.data;
            this.renderFull(ticketsRes.data || [], ticketsRes.meta || {});
        } catch (e) {
            $('#cust-detail-wrap').html('<div class="alert alert-danger m-4">' + App.escapeHtml(e.message) + '</div>');
        }
    },

    renderFull(tickets, ticketsMeta) {
        const c       = this.customer;
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
                            <button class="btn btn-sm btn-outline-primary mb-3" id="btn-send-invite">
                                <i class="bi bi-envelope me-1"></i>Send Portal Invite
                            </button>
                            <hr class="my-2">
                            <p class="small text-muted mb-2">Set a new portal password for this customer.</p>
                            <div id="admin-set-pw-error" class="alert alert-danger d-none py-2 small"></div>
                            <div class="mb-2">
                                <input type="password" class="form-control form-control-sm" id="admin-pw-new" placeholder="New password (min 8 chars)">
                            </div>
                            <div class="mb-2">
                                <input type="password" class="form-control form-control-sm" id="admin-pw-confirm" placeholder="Confirm password">
                            </div>
                            <button class="btn btn-sm btn-outline-secondary" id="btn-admin-set-pw">
                                <span class="spinner-border spinner-border-sm d-none me-1" id="admin-pw-spinner"></span>
                                <i class="bi bi-key me-1"></i>Set Password
                            </button>
                        </div>
                    </div>
                </div>

                <div class="col-lg-8">
                    <!-- Tickets -->
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-header bg-white fw-semibold py-2 d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-ticket-perforated me-2"></i>Tickets</span>
                            <div class="d-flex gap-2">
                                <a href="#/tickets?q=${encodeURIComponent(c.email)}" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-list me-1"></i>All tickets
                                </a>
                                <a href="#/tickets/new?customer_email=${encodeURIComponent(c.email)}&customer_name=${encodeURIComponent(c.name || '')}" class="btn btn-sm btn-primary">
                                    <i class="bi bi-plus-lg me-1"></i>New Ticket
                                </a>
                            </div>
                        </div>
                        <div class="card-body p-0" id="cust-tickets-body">
                            ${this.renderTicketsTable(tickets, ticketsMeta)}
                        </div>
                        <div id="cust-tickets-pagination" class="px-3 pb-2"></div>
                    </div>

                    <!-- Comments -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white fw-semibold py-2">
                            <i class="bi bi-chat-left-text me-2"></i>Comments by this Customer
                        </div>
                        <div class="card-body p-0" id="cust-replies-body">
                            <div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm"></div></div>
                        </div>
                        <div id="cust-replies-pagination" class="px-3 pb-2"></div>
                    </div>
                </div>
            </div>
        </div>`;

        $('#cust-detail-wrap').replaceWith(html);
        this.bindTicketPagination(ticketsMeta, 1);
        this.loadReplies(1);
        this.bindEvents();
    },

    renderTicketsTable(tickets, meta = {}) {
        if (!tickets.length) return '<p class="text-muted p-4 text-center mb-0">No tickets found.</p>';
        const rows = tickets.map(t => `
            <tr style="cursor:pointer;" onclick="App.navigate('/tickets/${t.id}')">
                <td><span class="font-monospace small">${App.escapeHtml(t.ticket_number)}</span></td>
                <td class="small">${App.escapeHtml(t.subject)}</td>
                <td>${App.statusBadge(t.status)}</td>
                <td>${App.priorityBadge(t.priority)}</td>
                <td class="small text-muted text-nowrap">${App.formatDate(t.updated_at)}</td>
            </tr>`).join('');
        const total = meta.total || tickets.length;
        return `
            <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom bg-light">
                <span class="small text-muted">${total} ticket${total !== 1 ? 's' : ''} total</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>Ticket #</th><th>Subject</th><th>Status</th><th>Priority</th><th>Updated</th></tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>`;
    },

    bindTicketPagination(meta, currentPage) {
        if (!meta.last_page || meta.last_page <= 1) {
            $('#cust-tickets-pagination').empty();
            return;
        }
        $('#cust-tickets-pagination').html(this.paginationHtml(meta, currentPage, 'cust-ticket-page'));
        $('#cust-tickets-pagination').on('click', 'a[data-page]', async (e) => {
            e.preventDefault();
            const page    = parseInt($(e.currentTarget).data('page'));
            const perPage = parseInt(API.currentUser?.page_size) || 20;
            const res     = await API.get('/customers/' + this.customerId + '/tickets', { page, per_page: perPage });
            $('#cust-tickets-body').html(this.renderTicketsTable(res.data || [], res.meta || {}));
            this.bindTicketPagination(res.meta || {}, page);
        });
    },

    async loadReplies(page = 1) {
        $('#cust-replies-body').html('<div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm"></div></div>');
        try {
            const perPage = parseInt(API.currentUser?.page_size) || 20;
            const res     = await API.get('/customers/' + this.customerId + '/replies', { page, per_page: perPage });
            const replies = res.data || [];
            const meta    = res.meta  || {};

            if (!replies.length) {
                $('#cust-replies-body').html('<p class="text-muted p-4 text-center mb-0">No comments found.</p>');
                $('#cust-replies-pagination').empty();
                return;
            }

            const total = meta.total || replies.length;
            const rows  = replies.map(r => {
                const text = r.body_text
                    ? App.escapeHtml(r.body_text.replace(/\s+/g, ' ').trim()).substring(0, 300)
                    : r.body_html
                        ? App.escapeHtml($('<div>').html(r.body_html).text().replace(/\s+/g, ' ').trim()).substring(0, 300)
                        : '(no content)';
                return `
                <div class="border-bottom px-3 py-3">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <a href="#/tickets/${r.ticket_id}" class="fw-semibold small text-decoration-none">
                            <span class="font-monospace text-muted me-1">${App.escapeHtml(r.ticket_number)}</span>${App.escapeHtml(r.subject)}
                        </a>
                        <span class="small text-muted text-nowrap ms-3">${App.formatDate(r.created_at)}</span>
                    </div>
                    <div class="small text-muted" style="white-space:pre-wrap;">${text}${r.body_text && r.body_text.length > 300 ? '…' : ''}</div>
                </div>`;
            }).join('');

            $('#cust-replies-body').html(`
                <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom bg-light">
                    <span class="small text-muted">${total} comment${total !== 1 ? 's' : ''} total</span>
                </div>
                ${rows}`);

            if (meta.last_page && meta.last_page > 1) {
                $('#cust-replies-pagination').html(this.paginationHtml(meta, page, 'cust-reply-page'));
                $('#cust-replies-pagination').off('click').on('click', 'a[data-page]', (e) => {
                    e.preventDefault();
                    this.loadReplies(parseInt($(e.currentTarget).data('page')));
                });
            } else {
                $('#cust-replies-pagination').empty();
            }
        } catch (e) {
            $('#cust-replies-body').html('<p class="text-danger p-3 mb-0">' + App.escapeHtml(e.message) + '</p>');
        }
    },

    paginationHtml(meta, currentPage, prefix) {
        let pages = '';
        for (let i = 1; i <= meta.last_page; i++) {
            if (Math.abs(i - currentPage) > 3 && i !== 1 && i !== meta.last_page) {
                if (i === currentPage - 4 || i === currentPage + 4) pages += '<li class="page-item disabled"><span class="page-link">…</span></li>';
                continue;
            }
            pages += `<li class="page-item ${i === currentPage ? 'active' : ''}">
                <a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
        }
        return `<nav class="mt-2"><ul class="pagination pagination-sm justify-content-center mb-0">
            <li class="page-item ${currentPage <= 1 ? 'disabled' : ''}">
                <a class="page-link" href="#" data-page="${currentPage - 1}">‹</a>
            </li>
            ${pages}
            <li class="page-item ${currentPage >= meta.last_page ? 'disabled' : ''}">
                <a class="page-link" href="#" data-page="${currentPage + 1}">›</a>
            </li>
        </ul></nav>`;
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

        $('#btn-admin-set-pw').on('click', async () => {
            const nw      = $('#admin-pw-new').val();
            const confirm = $('#admin-pw-confirm').val();
            $('#admin-set-pw-error').addClass('d-none');

            if (nw.length < 8) {
                $('#admin-set-pw-error').text('Password must be at least 8 characters.').removeClass('d-none');
                return;
            }
            if (nw !== confirm) {
                $('#admin-set-pw-error').text('Passwords do not match.').removeClass('d-none');
                return;
            }

            $('#admin-pw-spinner').removeClass('d-none');
            $('#btn-admin-set-pw').prop('disabled', true);
            try {
                await API.post('/customers/' + this.customer.id + '/set-password', {
                    password: nw,
                    password_confirm: confirm,
                });
                $('#admin-pw-new, #admin-pw-confirm').val('');
                App.toast('Customer password updated');
            } catch (e) {
                $('#admin-set-pw-error').text(e.message).removeClass('d-none');
            } finally {
                $('#admin-pw-spinner').addClass('d-none');
                $('#btn-admin-set-pw').prop('disabled', false);
            }
        });
    }
};
