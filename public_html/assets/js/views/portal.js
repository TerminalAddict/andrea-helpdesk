/**
 * Portal Magic-Link Login View
 * Handles the link sent by "Send Portal Invite": #/portal/login?token=...&email=...
 */
const PortalLoginView = {
    render() {
        return `
        <div class="container py-5" style="max-width:480px;">
            <div class="card border-0 shadow-sm text-center p-4">
                <div class="spinner-border mx-auto mb-3" id="portal-login-spinner"></div>
                <p class="text-muted mb-0" id="portal-login-msg">Verifying your login link…</p>
            </div>
        </div>`;
    },

    async init(params) {
        const token = params.token || '';
        const email = params.email || '';

        if (!token || !email) {
            $('#portal-login-spinner').addClass('d-none');
            $('#portal-login-msg').text('Invalid login link — token or email missing.');
            return;
        }

        try {
            const res = await API.post('/portal/auth/verify-magic-link', { token, email });
            API.setTokens(res.data.access_token, res.data.refresh_token);
            await API.loadCurrentUser();
            App.navigate(res.data.user.has_password ? '/portal' : '/portal/set-password');
        } catch (e) {
            $('#portal-login-spinner').addClass('d-none');
            $('#portal-login-msg').html(
                '<span class="text-danger">' + App.escapeHtml(e.message) + '</span><br>' +
                '<small class="text-muted">This link may have expired. Contact support for a new one.</small>'
            );
        }
    }
};

/**
 * Portal Set Password View
 * Shown after first magic-link login to complete profile setup.
 */
const PortalSetPasswordView = {
    render() {
        return `
        <div class="container py-5" style="max-width:480px;">
            <div class="card border-0 shadow-sm p-4">
                <h5 class="fw-bold mb-1"><i class="bi bi-shield-lock me-2"></i>Complete Your Profile</h5>
                <p class="text-muted small mb-4">Set a password so you can log in and reply to support tickets in future.</p>
                <div id="set-pw-error" class="alert alert-danger d-none"></div>
                <div class="mb-3">
                    <label class="form-label">New Password</label>
                    <input type="password" class="form-control" id="set-pw-password" placeholder="At least 8 characters" minlength="8">
                </div>
                <div class="mb-4">
                    <label class="form-label">Confirm Password</label>
                    <input type="password" class="form-control" id="set-pw-confirm" placeholder="Repeat password">
                </div>
                <button class="btn btn-primary w-100" id="btn-set-pw">
                    <span class="spinner-border spinner-border-sm d-none me-1" id="set-pw-spinner"></span>
                    Set Password &amp; Continue
                </button>
            </div>
        </div>`;
    },

    async init() {
        $('#btn-set-pw').on('click', () => this.submit());
        $('#set-pw-password, #set-pw-confirm').on('keydown', (e) => {
            if (e.key === 'Enter') this.submit();
        });
    },

    async submit() {
        const password = $('#set-pw-password').val();
        const confirm  = $('#set-pw-confirm').val();

        if (password.length < 8) {
            $('#set-pw-error').text('Password must be at least 8 characters.').removeClass('d-none');
            return;
        }
        if (password !== confirm) {
            $('#set-pw-error').text('Passwords do not match.').removeClass('d-none');
            return;
        }

        $('#set-pw-spinner').removeClass('d-none');
        $('#btn-set-pw').prop('disabled', true);
        $('#set-pw-error').addClass('d-none');

        try {
            await API.post('/portal/auth/set-password', { password, password_confirm: confirm });
            // Update local user state
            if (API.currentUser) API.currentUser.has_password = true;
            App.toast('Password set successfully');
            App.navigate('/portal');
        } catch (e) {
            $('#set-pw-error').text(e.message).removeClass('d-none');
        } finally {
            $('#set-pw-spinner').addClass('d-none');
            $('#btn-set-pw').prop('disabled', false);
        }
    }
};

/**
 * Customer Portal View
 */
const PortalView = {
    render() {
        return `
        <div class="container py-4" style="max-width:900px;">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4 class="mb-0"><i class="bi bi-ticket me-2"></i>My Support Tickets</h4>
                <a href="#" id="portal-new-ticket" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg me-1"></i>New Ticket
                </a>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body py-2">
                    <div class="row g-2">
                        <div class="col-md-3">
                            <select class="form-select form-select-sm" id="portal-filter-status">
                                <option value="">All Statuses</option>
                                <option value="open" selected>Open</option>
                                <option value="pending">Pending</option>
                                <option value="resolved">Resolved</option>
                                <option value="closed">Closed</option>
                            </select>
                        </div>
                        <div class="col-md-1">
                            <button class="btn btn-secondary btn-sm w-100" id="portal-filter-reset">Clear</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center" style="cursor:pointer;" id="toggle-change-pw">
                    <span class="small fw-semibold"><i class="bi bi-key me-2"></i>Change Password</span>
                    <i class="bi bi-chevron-down small" id="change-pw-chevron"></i>
                </div>
                <div class="card-body d-none" id="change-pw-form">
                    <div id="change-pw-error" class="alert alert-danger d-none"></div>
                    <div id="change-pw-success" class="alert alert-success d-none"></div>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label small">Current Password</label>
                            <input type="password" class="form-control form-control-sm" id="cp-current">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">New Password</label>
                            <input type="password" class="form-control form-control-sm" id="cp-new" placeholder="At least 8 characters">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Confirm New Password</label>
                            <input type="password" class="form-control form-control-sm" id="cp-confirm">
                        </div>
                    </div>
                    <div class="mt-3">
                        <button class="btn btn-primary btn-sm" id="btn-change-pw">
                            <span class="spinner-border spinner-border-sm d-none me-1" id="change-pw-spinner"></span>
                            Update Password
                        </button>
                    </div>
                </div>
            </div>

            <div id="portal-tickets-wrap" class="card border-0 shadow-sm">
                <div class="card-body p-0">
                    <div class="text-center py-5 text-muted">
                        <div class="spinner-border"></div><p class="mt-2">Loading…</p>
                    </div>
                </div>
            </div>
            <div id="portal-pagination" class="mt-3"></div>
        </div>

        <!-- New Ticket Modal -->
        <div class="modal fade" id="portalNewTicketModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">New Support Ticket</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div id="portal-new-error" class="alert alert-danger d-none"></div>
                        <div class="mb-3">
                            <label class="form-label">Subject <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="portal-new-subject" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Message <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="portal-new-body" rows="6" required></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Attachments</label>
                            <input type="file" class="form-control" id="portal-new-files" multiple>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="btn-portal-submit">
                            <span class="spinner-border spinner-border-sm d-none me-1" id="portal-submit-spinner"></span>
                            Submit Ticket
                        </button>
                    </div>
                </div>
            </div>
        </div>`;
    },

    async init() {
        $('#portal-filter-status').on('change', () => this.load());
        $('#portal-filter-reset').on('click', () => {
            $('#portal-filter-status').val('open');
            this.load();
        });
        $('#portal-new-ticket').on('click', (e) => {
            e.preventDefault();
            new bootstrap.Modal(document.getElementById('portalNewTicketModal')).show();
        });
        $('#btn-portal-submit').on('click', () => this.submitNewTicket());

        // Change password toggle
        $('#toggle-change-pw').on('click', () => {
            $('#change-pw-form').toggleClass('d-none');
            $('#change-pw-chevron').toggleClass('bi-chevron-down bi-chevron-up');
        });

        $('#btn-change-pw').on('click', async () => {
            const current = $('#cp-current').val();
            const nw      = $('#cp-new').val();
            const confirm = $('#cp-confirm').val();

            $('#change-pw-error').addClass('d-none');
            $('#change-pw-success').addClass('d-none');

            if (nw.length < 8) {
                $('#change-pw-error').text('New password must be at least 8 characters.').removeClass('d-none');
                return;
            }
            if (nw !== confirm) {
                $('#change-pw-error').text('New passwords do not match.').removeClass('d-none');
                return;
            }

            $('#change-pw-spinner').removeClass('d-none');
            $('#btn-change-pw').prop('disabled', true);
            try {
                await API.post('/portal/auth/change-password', {
                    current_password: current,
                    password: nw,
                    password_confirm: confirm,
                });
                $('#cp-current, #cp-new, #cp-confirm').val('');
                $('#change-pw-success').text('Password updated successfully.').removeClass('d-none');
                if (API.currentUser) API.currentUser.has_password = true;
            } catch (e) {
                $('#change-pw-error').text(e.message).removeClass('d-none');
            } finally {
                $('#change-pw-spinner').addClass('d-none');
                $('#btn-change-pw').prop('disabled', false);
            }
        });

        await this.load();
    },

    async load(page = 1) {
        const params = { page, per_page: 20 };
        const status = $('#portal-filter-status').val();
        if (status) params.status = status;

        try {
            const res = await API.get('/portal/tickets', params);
            this.renderTable(res.data || [], res.meta || {}, page);
        } catch (e) {
            $('#portal-tickets-wrap').html('<p class="text-danger p-3">' + App.escapeHtml(e.message) + '</p>');
        }
    },

    renderTable(tickets, meta, currentPage) {
        if (!tickets.length) {
            $('#portal-tickets-wrap').html('<p class="text-muted p-4 text-center">No tickets found.</p>');
            return;
        }

        const total = meta.total || 0;
        const rows = tickets.map(t => `
            <tr class="portal-ticket-row" data-id="${t.id}" style="cursor:pointer;">
                <td><span class="font-monospace small fw-semibold">${App.escapeHtml(t.ticket_number)}</span></td>
                <td class="small">${App.escapeHtml(t.subject)}</td>
                <td>${App.statusBadge(t.status)}</td>
                <td class="small text-muted text-nowrap">${App.formatDate(t.updated_at)}</td>
            </tr>`).join('');

        $('#portal-tickets-wrap').html(`
            <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom bg-light">
                <span class="small text-muted">${total} ticket${total !== 1 ? 's' : ''}</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>Ticket #</th><th>Subject</th><th>Status</th><th>Updated</th></tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>`);
        $('#portal-tickets-wrap').on('click', '.portal-ticket-row', function() {
            App.navigate('/portal/tickets/' + $(this).data('id'));
        });

        // Pagination
        if (meta.last_page > 1) {
            const pages = Array.from({length: meta.last_page}, (_, i) => i + 1)
                .map(i => `<li class="page-item ${i === currentPage ? 'active' : ''}">
                    <a class="page-link" href="#" data-page="${i}">${i}</a></li>`)
                .join('');
            $('#portal-pagination').html(`
                <nav><ul class="pagination pagination-sm justify-content-center">
                    <li class="page-item ${currentPage <= 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${currentPage-1}">‹</a></li>
                    ${pages}
                    <li class="page-item ${currentPage >= meta.last_page ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${currentPage+1}">›</a></li>
                </ul></nav>`);

            $('#portal-pagination').on('click', 'a[data-page]', (e) => {
                e.preventDefault();
                this.load(parseInt($(e.currentTarget).data('page')));
            });
        } else {
            $('#portal-pagination').empty();
        }
    },

    async submitNewTicket() {
        const subject = $('#portal-new-subject').val().trim();
        const body    = $('#portal-new-body').val().trim();
        const files   = document.getElementById('portal-new-files').files;

        if (!subject || !body) {
            $('#portal-new-error').text('Subject and message are required').removeClass('d-none');
            return;
        }

        $('#portal-submit-spinner').removeClass('d-none');
        $('#btn-portal-submit').prop('disabled', true);
        $('#portal-new-error').addClass('d-none');

        try {
            const res = await API.post('/portal/tickets', { subject, body });
            const ticketId = res.data.id;

            for (const file of files) {
                const fd = new FormData();
                fd.append('file', file);
                await API.upload('/portal/tickets/' + ticketId + '/attachments', fd);
            }

            bootstrap.Modal.getInstance(document.getElementById('portalNewTicketModal')).hide();
            $('#portal-new-subject').val('');
            $('#portal-new-body').val('');
            App.toast('Ticket submitted successfully');
            App.navigate('/portal/tickets/' + ticketId);
        } catch (e) {
            $('#portal-new-error').text(e.message).removeClass('d-none');
        } finally {
            $('#portal-submit-spinner').addClass('d-none');
            $('#btn-portal-submit').prop('disabled', false);
        }
    }
};

/**
 * Customer Portal - Ticket Detail View
 */
const PortalTicketView = {
    ticket: null,

    render() {
        return `
        <div class="container py-4" style="max-width:800px;" id="portal-ticket-wrap">
            <div class="text-center py-5 text-muted">
                <div class="spinner-border"></div>
            </div>
        </div>`;
    },

    async init(params) {
        try {
            const res = await API.get('/portal/tickets/' + params.id);
            this.ticket = res.data;
            this.renderFull();
        } catch (e) {
            $('#portal-ticket-wrap').html('<div class="alert alert-danger">' + App.escapeHtml(e.message) + '</div>');
        }
    },

    renderFull() {
        const t = this.ticket;
        const html = `
        <div class="container py-4" style="max-width:800px;" id="portal-ticket-wrap">
            <nav aria-label="breadcrumb" class="mb-3">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="#/portal">My Tickets</a></li>
                    <li class="breadcrumb-item active">${App.escapeHtml(t.ticket_number)}</li>
                </ol>
            </nav>

            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <h5 class="fw-bold mb-1">${App.escapeHtml(t.subject)}</h5>
                    <div>
                        ${App.statusBadge(t.status)}
                        <span class="text-muted small ms-2">Opened ${App.formatDate(t.created_at)}</span>
                    </div>
                </div>
            </div>

            <!-- Thread -->
            <div id="portal-thread" class="mb-4"></div>

            <!-- Reply form (only if not closed) -->
            ${t.status !== 'closed' ? (
                API.currentUser && !API.currentUser.has_password ? `
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-4">
                    <i class="bi bi-shield-lock fs-3 text-muted mb-2 d-block"></i>
                    <p class="mb-2 fw-semibold">Please complete your profile to reply to this ticket.</p>
                    <p class="text-muted small mb-3">A confirmation email will be sent to <strong>${App.escapeHtml(API.currentUser.email)}</strong> with a link to set your password.</p>
                    <button class="btn btn-primary btn-sm" id="btn-send-setup-email">
                        <span class="spinner-border spinner-border-sm d-none me-1" id="setup-email-spinner"></span>
                        <i class="bi bi-envelope me-1"></i>Send Confirmation Email
                    </button>
                    <div id="setup-email-sent" class="alert alert-success mt-3 d-none">
                        <i class="bi bi-check-circle me-2"></i>Email sent! Check your inbox and click the link to set your password.
                    </div>
                </div>
            </div>` : `
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold py-2">
                    <i class="bi bi-reply me-2"></i>Reply
                </div>
                <div class="card-body">
                    <textarea class="form-control" id="portal-reply-body" rows="5" placeholder="Write your message…"></textarea>
                    <div class="mt-2">
                        <input type="file" class="form-control form-control-sm" id="portal-reply-files" multiple>
                    </div>
                </div>
                <div class="card-footer bg-white text-end">
                    <button class="btn btn-primary btn-sm" id="btn-portal-reply">
                        <span class="spinner-border spinner-border-sm d-none me-1" id="portal-reply-spinner"></span>
                        <i class="bi bi-send me-1"></i>Send
                    </button>
                </div>
            </div>`) : `
            <div class="alert alert-secondary">This ticket is closed. <a href="#/portal">Submit a new ticket</a> if you need further assistance.</div>`}
        </div>`;

        $('#portal-ticket-wrap').replaceWith(html);
        this.renderThread(t.replies || []);

        $('#btn-portal-reply').on('click', () => this.sendReply());

        $('#btn-send-setup-email').on('click', async () => {
            $('#setup-email-spinner').removeClass('d-none');
            $('#btn-send-setup-email').prop('disabled', true);
            try {
                await API.post('/portal/auth/magic-link', { email: API.currentUser.email });
                $('#setup-email-sent').removeClass('d-none');
            } catch (e) {
                App.toast(e.message, 'error');
                $('#btn-send-setup-email').prop('disabled', false);
            } finally {
                $('#setup-email-spinner').addClass('d-none');
            }
        });
    },

    renderThread(replies) {
        const html = replies
            .filter(r => r.type !== 'internal')
            .map(r => {
                const isAgent = r.author_type === 'agent';
                const side    = isAgent ? 'ms-0 me-4' : 'ms-4 me-0';
                const bg      = isAgent ? 'bg-light' : 'bg-primary bg-opacity-10 border-primary';

                const attachments = (r.attachments || []).map(a =>
                    `<a href="/attachment/${a.id}?token=${a.token || ''}" target="_blank" class="btn btn-sm btn-outline-secondary me-1 mt-1">
                        <i class="bi bi-paperclip me-1"></i>${App.escapeHtml(a.filename)}
                    </a>`
                ).join('');

                return `
                <div class="mb-3 ${side}">
                    <div class="card border-0 shadow-sm ${bg}">
                        <div class="card-header bg-transparent border-bottom py-2 d-flex justify-content-between">
                            <strong class="small">${App.escapeHtml(r.author_name || (isAgent ? 'Support' : 'You'))}</strong>
                            <span class="text-muted small">${App.formatDate(r.created_at)}</span>
                        </div>
                        <div class="card-body py-3">
                            <pre class="mb-0" style="white-space:pre-wrap;font-family:inherit;">${App.escapeHtml(r.body || '')}</pre>
                            ${attachments ? `<div class="mt-2">${attachments}</div>` : ''}
                        </div>
                    </div>
                </div>`;
            }).join('');

        $('#portal-thread').html(html || '<p class="text-muted small">No messages yet.</p>');
    },

    async sendReply() {
        const body  = $('#portal-reply-body').val().trim();
        const files = document.getElementById('portal-reply-files').files;

        if (!body) { App.toast('Message is required', 'warning'); return; }

        $('#portal-reply-spinner').removeClass('d-none');
        $('#btn-portal-reply').prop('disabled', true);

        try {
            await API.post('/portal/tickets/' + this.ticket.id + '/replies', { body });

            for (const file of files) {
                const fd = new FormData();
                fd.append('file', file);
                await API.upload('/portal/tickets/' + this.ticket.id + '/attachments', fd);
            }

            $('#portal-reply-body').val('');
            $('#portal-reply-files').val('');

            const res = await API.get('/portal/tickets/' + this.ticket.id);
            this.ticket = res.data;
            this.renderThread(this.ticket.replies || []);
            App.toast('Reply sent');
        } catch (e) {
            App.toast(e.message, 'error');
        } finally {
            $('#portal-reply-spinner').addClass('d-none');
            $('#btn-portal-reply').prop('disabled', false);
        }
    }
};
