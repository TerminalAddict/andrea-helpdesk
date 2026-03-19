/**
 * New Ticket View (create on behalf of customer / phone channel)
 */
const TicketNewView = {
    render(params) {
        this._params = params || {};
        return `
        <div class="container-fluid p-4" style="max-width:800px;">
            <nav aria-label="breadcrumb" class="mb-3">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="#/tickets">Tickets</a></li>
                    <li class="breadcrumb-item active">New Ticket</li>
                </ol>
            </nav>
            <h5 class="fw-bold mb-4"><i class="bi bi-plus-circle me-2"></i>Create New Ticket</h5>

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <form id="new-ticket-form">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label">Customer Email <span class="text-danger">*</span></label>
                                <div class="position-relative">
                                    <input type="text" class="form-control" id="nt-customer-email" required placeholder="Search by name or email…" autocomplete="off">
                                    <ul class="list-group shadow position-absolute w-100 z-3 d-none" id="nt-customer-suggestions" style="top:100%;max-height:220px;overflow-y:auto;"></ul>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Customer Name</label>
                                <input type="text" class="form-control" id="nt-customer-name" placeholder="Full Name">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Subject <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="nt-subject" required placeholder="Brief description of the issue">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Priority</label>
                                <select class="form-select" id="nt-priority">
                                    <option value="normal" selected>Normal</option>
                                    <option value="low">Low</option>
                                    <option value="high">High</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Channel</label>
                                <select class="form-select" id="nt-channel">
                                    <option value="phone">Phone</option>
                                    <option value="email">Email</option>
                                    <option value="web" selected>Web</option>
                                    <option value="portal">Portal</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Assign To</label>
                                <select class="form-select" id="nt-assigned">
                                    <option value="">Unassigned</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">CC / Participants</label>
                                <div class="border rounded p-2 d-flex flex-wrap gap-1 align-items-center" id="nt-cc-container" style="min-height:2.5rem;cursor:text;">
                                    <div class="position-relative d-flex" style="min-width:220px;flex:1;">
                                        <input type="text" class="form-control form-control-sm border-0 shadow-none p-0 ps-1" id="nt-cc-input" placeholder="Search name or email, press Enter to add…" autocomplete="off" style="outline:none;">
                                        <ul class="list-group shadow position-absolute w-100 z-3 d-none" id="nt-cc-suggestions" style="top:100%;min-width:280px;max-height:200px;overflow-y:auto;"></ul>
                                    </div>
                                </div>
                                <div class="form-text">CC'd participants receive copies of agent replies.</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Message <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="nt-body" rows="6" required placeholder="Describe the issue…"></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Attachments</label>
                                <input type="file" class="form-control" id="nt-files" multiple>
                            </div>
                            ${this._params.parent_id ? `
                            <div class="col-12">
                                <div class="alert alert-info mb-0 py-2">
                                    <i class="bi bi-diagram-2 me-2"></i>This will be created as a child ticket of #${App.escapeHtml(String(this._params.parent_id))}
                                </div>
                            </div>` : ''}
                        </div>
                        <div class="d-flex justify-content-end gap-2 mt-4">
                            <a href="#/tickets" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary" id="nt-submit">
                                <span class="spinner-border spinner-border-sm d-none me-1" id="nt-spinner"></span>
                                <i class="bi bi-plus-lg me-1"></i>Create Ticket
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>`;
    },

    async init(params) {
        this._params       = params || {};
        this._participants = [];

        // Load agents
        try {
            const res = await API.get('/agents');
            (res.data || []).forEach(a => {
                $('#nt-assigned').append(`<option value="${a.id}">${App.escapeHtml(a.name)}</option>`);
            });
            // Default assign to self
            if (API.currentUser && API.currentUser.id) {
                $('#nt-assigned').val(API.currentUser.id);
            }
        } catch (e) {}

        // Pre-fill customer if navigated from customer detail
        if (params && params.customer_email) {
            $('#nt-customer-email').val(decodeURIComponent(params.customer_email));
        }
        if (params && params.customer_name) {
            $('#nt-customer-name').val(decodeURIComponent(params.customer_name));
        }

        // Pre-fill parent if coming from child creation
        if (params && params.parent_id) {
            this._parentId = params.parent_id;
        }

        // Customer autocomplete
        let customerSearchTimer;
        $('#nt-customer-email').on('input', () => {
            clearTimeout(customerSearchTimer);
            const q = $('#nt-customer-email').val().trim();
            if (q.length < 2) { $('#nt-customer-suggestions').addClass('d-none').empty(); return; }
            customerSearchTimer = setTimeout(() => this.searchCustomers(q), 300);
        });
        $('#nt-customer-email').on('keydown', (e) => {
            const $list  = $('#nt-customer-suggestions');
            const $items = $list.find('li');
            if (!$items.length || $list.hasClass('d-none')) return;
            const $active = $items.filter('.nt-suggestion-active');
            let idx = $items.index($active);
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                idx = idx < $items.length - 1 ? idx + 1 : 0;
                $items.removeClass('nt-suggestion-active').eq(idx).addClass('nt-suggestion-active')[0]?.scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                idx = idx > 0 ? idx - 1 : $items.length - 1;
                $items.removeClass('nt-suggestion-active').eq(idx).addClass('nt-suggestion-active')[0]?.scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'Enter' && $active.length) {
                e.preventDefault();
                $active[0].click();
            } else if (e.key === 'Escape') {
                $list.addClass('d-none');
            }
        });

        // Hide suggestions when clicking outside
        $(document).on('click.newticket', (e) => {
            if (!$(e.target).closest('#nt-customer-email, #nt-customer-suggestions').length) {
                $('#nt-customer-suggestions').addClass('d-none');
            }
            if (!$(e.target).closest('#nt-cc-container, #nt-cc-suggestions').length) {
                $('#nt-cc-suggestions').addClass('d-none');
            }
        });

        // CC field — focus container clicks into input
        $('#nt-cc-container').on('click', () => $('#nt-cc-input').focus());

        // CC autocomplete
        let ccSearchTimer;
        $('#nt-cc-input').on('input', () => {
            clearTimeout(ccSearchTimer);
            const q = $('#nt-cc-input').val().trim();
            if (q.length < 2) { $('#nt-cc-suggestions').addClass('d-none').empty(); return; }
            ccSearchTimer = setTimeout(() => this.searchCcCustomers(q), 300);
        });

        // Enter or comma/tab on CC input adds raw email; arrow keys navigate suggestions
        $('#nt-cc-input').on('keydown', (e) => {
            const $list  = $('#nt-cc-suggestions');
            const $items = $list.find('li');
            const $active = $items.filter('.nt-suggestion-active');

            if (e.key === 'ArrowDown' && !$list.hasClass('d-none') && $items.length) {
                e.preventDefault();
                const idx = $items.index($active);
                const next = idx < $items.length - 1 ? idx + 1 : 0;
                $items.removeClass('nt-suggestion-active').eq(next).addClass('nt-suggestion-active')[0]?.scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'ArrowUp' && !$list.hasClass('d-none') && $items.length) {
                e.preventDefault();
                const idx = $items.index($active);
                const prev = idx > 0 ? idx - 1 : $items.length - 1;
                $items.removeClass('nt-suggestion-active').eq(prev).addClass('nt-suggestion-active')[0]?.scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'Enter' || e.key === ',' || e.key === 'Tab') {
                e.preventDefault();
                if ($active.length) {
                    $active[0].click();
                } else {
                    const val = $('#nt-cc-input').val().trim().replace(/,$/, '');
                    if (val) this.addCcParticipant(val, '');
                    $list.addClass('d-none').empty();
                }
            } else if (e.key === 'Backspace' && !$('#nt-cc-input').val() && this._participants.length) {
                this.removeCcParticipant(this._participants[this._participants.length - 1].email);
            }
        });

        $('#new-ticket-form').on('submit', (e) => {
            e.preventDefault();
            this.submit();
        });
    },

    async searchCustomers(q) {
        try {
            const res = await API.get('/customers', { q, per_page: 8 });
            const customers = res.data || [];
            const $list = $('#nt-customer-suggestions');

            if (!customers.length) { $list.addClass('d-none').empty(); return; }

            $list.empty();
            customers.forEach(c => {
                const $item = $(`<li class="list-group-item list-group-item-action py-2" style="cursor:pointer;">
                    <div class="fw-semibold small">${App.escapeHtml(c.name || c.email)}</div>
                    <div class="text-muted small">${App.escapeHtml(c.email)}</div>
                </li>`);
                $item.on('click', () => {
                    $('#nt-customer-email').val(c.email);
                    $('#nt-customer-name').val(c.name || '');
                    $list.addClass('d-none').empty();
                });
                $list.append($item);
            });
            $list.removeClass('d-none');
        } catch (e) {}
    },

    async searchCcCustomers(q) {
        try {
            const res = await API.get('/customers', { q, per_page: 8 });
            const customers = (res.data || []).filter(c => !this._participants.find(p => p.email === c.email));
            const $list = $('#nt-cc-suggestions');
            if (!customers.length) { $list.addClass('d-none').empty(); return; }
            $list.empty();
            customers.forEach(c => {
                const $item = $(`<li class="list-group-item list-group-item-action py-2" style="cursor:pointer;">
                    <div class="fw-semibold small">${App.escapeHtml(c.name || c.email)}</div>
                    <div class="text-muted small">${App.escapeHtml(c.email)}</div>
                </li>`);
                $item.on('click', () => {
                    this.addCcParticipant(c.email, c.name || '');
                    $('#nt-cc-suggestions').addClass('d-none').empty();
                    $('#nt-cc-input').val('').focus();
                });
                $list.append($item);
            });
            $list.removeClass('d-none');
        } catch (e) {}
    },

    addCcParticipant(email, name) {
        if (!email.includes('@')) return;
        if (this._participants.find(p => p.email === email)) return;
        this._participants.push({ email, name });
        const $chip = $(`<span class="badge bg-secondary d-flex align-items-center gap-1 cc-chip" data-email="${App.escapeHtml(email)}" style="font-size:.8rem;font-weight:500;">
            ${App.escapeHtml(name || email)}
            <button type="button" class="btn-close btn-close-white" style="font-size:.6rem;" aria-label="Remove"></button>
        </span>`);
        $chip.find('.btn-close').on('click', () => this.removeCcParticipant(email));
        $('#nt-cc-input').before($chip);
        $('#nt-cc-input').val('');
    },

    removeCcParticipant(email) {
        this._participants = this._participants.filter(p => p.email !== email);
        $(`#nt-cc-container .cc-chip[data-email="${CSS.escape(email)}"]`).remove();
    },

    async submit() {
        const email    = $('#nt-customer-email').val().trim();
        const name     = $('#nt-customer-name').val().trim();
        const subject  = $('#nt-subject').val().trim();
        const body     = $('#nt-body').val().trim();
        const priority = $('#nt-priority').val();
        const channel  = $('#nt-channel').val();
        const assigned = $('#nt-assigned').val();
        const files    = document.getElementById('nt-files').files;

        $('#nt-spinner').removeClass('d-none');
        $('#nt-submit').prop('disabled', true);

        try {
            const payload = {
                customer_email: email,
                customer_name:  name || undefined,
                subject,
                body,
                priority,
                channel,
                assigned_agent_id: assigned || undefined,
            };
            if (this._parentId) payload.parent_ticket_id = this._parentId;

            const res = await API.post('/tickets', payload);
            const ticketId = res.data.id;

            // Add CC participants
            for (const p of this._participants) {
                await API.post('/tickets/' + ticketId + '/participants', { email: p.email, name: p.name });
            }

            // Upload attachments
            for (const file of files) {
                const fd = new FormData();
                fd.append('file', file);
                await API.upload('/tickets/' + ticketId + '/attachments', fd);
            }

            App.toast('Ticket created successfully', 'success');
            App.navigate('/tickets/' + ticketId);
        } catch (e) {
            App.toast(e.message, 'error');
        } finally {
            $('#nt-spinner').addClass('d-none');
            $('#nt-submit').prop('disabled', false);
        }
    }
};
