/**
 * Tickets List View
 */
const TicketsView = {
    agents: [],
    tags: [],

    render() {
        return `
        <div class="container-fluid terminal-screen terminal-screen-tickets p-4">
            <div class="terminal-screen-header d-flex justify-content-between align-items-center mb-3">
                <h4 class="mb-0"><i class="bi bi-ticket-perforated me-2"></i>Tickets</h4>
                <a href="#/tickets/new" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg me-1"></i>New Ticket
                </a>
            </div>

            <!-- Filters -->
            <div class="card border-0 shadow-sm terminal-control-card mb-3">
                <div class="card-body py-2">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-2">
                            <select class="form-select form-select-sm" id="filter-status">
                                <option value="">All Statuses</option>
                                <option value="active" selected>Active (all open)</option>
                                <option value="new">New</option>
                                <option value="waiting_for_reply">Waiting for reply</option>
                                <option value="replied">Replied</option>
                                <option value="open">Open</option>
                                <option value="pending">Pending</option>
                                <option value="resolved">Resolved</option>
                                <option value="closed">Closed</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select class="form-select form-select-sm" id="filter-priority">
                                <option value="">All Priorities</option>
                                <option value="overdue">Overdue</option>
                                <option value="urgent">Urgent</option>
                                <option value="high">High</option>
                                <option value="normal">Normal</option>
                                <option value="low">Low</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select class="form-select form-select-sm" id="filter-agent">
                                <option value="">All Agents</option>
                                <option value="unassigned">Unassigned</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select class="form-select form-select-sm" id="filter-tag">
                                <option value="">All Tags</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <input type="search" class="form-control form-control-sm" id="filter-q" placeholder="Search tickets...">
                        </div>
                        <div class="col-md-1">
                            <button class="btn btn-secondary btn-sm w-100" id="filter-reset">Clear</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Results -->
            <div class="card border-0 shadow-sm terminal-table-card">
                <div class="card-body p-0">
                    <div id="tickets-table-wrap">
                        <div class="text-center py-5 text-muted">
                            <div class="spinner-border"></div><p class="mt-2">Loading tickets...</p>
                        </div>
                    </div>
                </div>
            </div>

            <div id="tickets-pagination" class="mt-3"></div>
        </div>`;
    },

    async init(params) {
        this._view = {};
        this._selected = new Set();
        this._tickets = [];
        this._bulkBusy = false;
        this._loading = false;
        this._page = 1;
        this._loadId = 0;
        // Load agents and tags for filters
        try {
            const [agentsRes, tagsRes] = await Promise.all([API.get('/agents'), API.get('/tags')]);
            this.agents = agentsRes.data || [];
            this.agents.forEach(a => {
                $('#filter-agent').append(`<option value="${a.id}">${App.escapeHtml(a.name)}</option>`);
            });
            this.tags = tagsRes.data || [];
            this.tags.forEach(t => {
                $('#filter-tag').append(`<option value="${t.id}">${App.escapeHtml(t.name)}</option>`);
            });
        } catch (e) {}

        // Pre-populate filters from query params (e.g. links from dashboard)
        this._sort = null;
        this._dir  = null;
        if (params) {
            if (params.status)      $('#filter-status').val(params.status);
            if (params.priority)    $('#filter-priority').val(params.priority);
            if (params.assigned_to) $('#filter-agent').val(params.assigned_to);
            if (params.tag_id)      $('#filter-tag').val(params.tag_id);
            if (params.q)           $('#filter-q').val(params.q);
            if (params.sort)        this._sort = params.sort;
            if (params.dir)         this._dir  = params.dir;
        }

        // Bind filters
        let searchTimer;
        $('#filter-q').on('input', () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => this.loadTickets(), 400);
        });
        $('#filter-status, #filter-priority, #filter-agent, #filter-tag').on('change', () => this.loadTickets());
        $('#filter-reset').on('click', () => {
            $('#filter-status').val('active');
            $('#filter-priority, #filter-agent, #filter-tag').val('');
            $('#filter-q').val('');
            this._sort = null;
            this._dir  = null;
            this.loadTickets();
        });

        this.loadTickets();
    },

    async loadTickets(page = 1) {
        if (this._bulkBusy) return;
        const view = this._view;
        const loadId = ++this._loadId;
        this._selected.clear();
        this.updateBulkControls();
        this._loading = true;
        $('#tickets-table-wrap input[type="checkbox"]').prop('disabled', true);
        const perPage = parseInt(API.currentUser?.page_size) || 20;
        const filters = {
            status:      $('#filter-status').val() || undefined,
            priority:    $('#filter-priority').val() || undefined,
            assigned_to: $('#filter-agent').val() || undefined,
            tag_id:      $('#filter-tag').val() || undefined,
            q:           $('#filter-q').val() || undefined,
            sort:        this._sort || undefined,
            dir:         this._dir  || undefined,
            page,
            per_page: perPage,
        };

        // Remove undefined keys
        Object.keys(filters).forEach(k => filters[k] === undefined && delete filters[k]);

        try {
            const res = await API.get('/tickets', filters);
            if (view !== this._view || loadId !== this._loadId) return;
            this._loading = false;
            this._page = page;
            this._tickets = res.data || [];
            if (!this._tickets.length && page > 1) {
                return this.loadTickets(page - 1);
            }
            this.renderTable(res.data || [], res.meta || {});
            this.renderPagination(res.meta || {}, page);
        } catch (e) {
            if (view !== this._view || loadId !== this._loadId) return;
            this._loading = false;
            this._tickets = [];
            $('#tickets-pagination').empty();
            $('#tickets-table-wrap').html('<p class="text-danger p-3">Failed to load tickets: ' + App.escapeHtml(e.message) + '</p>');
        }
    },

    renderTable(tickets, meta) {
        const canResolve = API.isAdmin() || API.can('can_close_tickets');
        const canDelete = API.isAdmin() || API.can('can_delete_tickets');
        const canSelect = canResolve || canDelete;
        if (!tickets.length) {
            $('#tickets-table-wrap').html('<p class="text-muted p-4 text-center">No tickets found.</p>');
            return;
        }

        // Group children under their parents within this page's results
        const childrenOf = {};
        const topLevel   = [];
        tickets.forEach(t => {
            if (t.parent_ticket_id) {
                (childrenOf[t.parent_ticket_id] = childrenOf[t.parent_ticket_id] || []).push(t);
            } else {
                topLevel.push(t);
            }
        });

        // Order: parent row, then its children; orphan children (parent not on page) appended at end
        const seenChildIds = new Set();
        const ordered = [];
        topLevel.forEach(t => {
            ordered.push({ t, child: false });
            (childrenOf[t.id] || []).forEach(c => { ordered.push({ t: c, child: true }); seenChildIds.add(c.id); });
        });
        tickets.filter(t => t.parent_ticket_id && !seenChildIds.has(t.id)).forEach(t => ordered.push({ t, child: true }));

        const rows = ordered.map(({ t, child }) => `
            <tr class="ticket-row${child ? ' table-light ticket-child-row' : ''}${t.priority === 'overdue' ? ' ticket-overdue-row' : ''}" data-id="${t.id}" style="cursor:pointer;">
                ${canSelect ? `<td class="ticket-selection"><input type="checkbox" class="form-check-input ticket-select" value="${Number(t.id)}" aria-label="Select ${App.escapeHtml(t.ticket_number)}"></td>` : ''}
                <td class="ticket-number-column">
                    ${child ? '<span class="text-muted me-1" style="padding-left:1rem;">↳</span>' : ''}
                    <span class="font-monospace small fw-semibold">${App.escapeHtml(t.ticket_number)}</span>
                </td>
                <td class="ticket-subject-column">
                    <div class="ticket-subject-text">${child ? '<span class="d-md-none text-muted me-1" aria-label="Child ticket">&#8627;</span>' : ''}${App.escapeHtml(t.subject)}</div>
                    <div class="small text-muted">${App.escapeHtml(t.customer_name || '')} ${t.customer_email ? '&lt;' + App.escapeHtml(t.customer_email) + '&gt;' : ''}</div>
                </td>
                <td>${App.statusBadge(t.status)}</td>
                <td>${App.priorityBadge(t.priority)}</td>
                <td>${t.tag_names ? t.tag_names.split(',').map(tag => `<span class="badge bg-secondary me-1">${App.escapeHtml(tag)}</span>`).join('') : ''}</td>
                <td class="small text-center text-muted">${t.reply_count > 0 ? t.reply_count : '–'}</td>
                <td class="small ${t.priority === 'overdue' ? 'ticket-overdue-agent' : ''}">${t.agent_name ? App.escapeHtml(t.agent_name) : '<em class="text-muted">Unassigned</em>'}</td>
                <td class="small text-muted text-nowrap">${App.formatDate(t.created_at)}</td>
                <td class="small text-muted text-nowrap">${App.formatDate(t.updated_at)}</td>
            </tr>`).join('');

        const sortIcon = (field) => {
            if (this._sort !== field) return '<i class="bi bi-arrow-down-up text-muted ms-1" style="font-size:.75rem;"></i>';
            return this._dir === 'asc'
                ? '<i class="bi bi-arrow-up ms-1" style="font-size:.75rem;"></i>'
                : '<i class="bi bi-arrow-down ms-1" style="font-size:.75rem;"></i>';
        };
        const th = (label, field) => field
            ? `<th data-sort="${field}" style="cursor:pointer;white-space:nowrap;user-select:none;">${label}${sortIcon(field)}</th>`
            : `<th>${label}</th>`;

        const total = meta.total || 0;
        $('#tickets-table-wrap').html(`
            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center px-3 py-2 border-bottom bg-light">
                <span class="small text-muted">${total} ticket${total !== 1 ? 's' : ''}</span>
                ${canSelect ? `<div class="d-flex flex-wrap gap-2 align-items-center">
                    <span id="ticket-selection-count" class="small" role="status" aria-live="polite">0 selected on this page</span>
                    ${canResolve ? '<button type="button" id="tickets-bulk-resolve" class="btn btn-sm btn-outline-success" disabled><i class="bi bi-check2-circle me-1"></i>Resolve selected</button>' : ''}
                    ${canDelete ? '<button type="button" id="tickets-bulk-delete" class="btn btn-sm btn-outline-danger" disabled><i class="bi bi-trash me-1"></i>Delete selected</button>' : ''}
                    <button type="button" id="tickets-clear-selection" class="btn btn-sm btn-outline-secondary" disabled>Clear selection</button>
                </div>` : ''}
            </div>
            <div id="tickets-bulk-result" class="px-3" role="status" aria-live="polite"></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            ${canSelect ? '<th class="ticket-selection"><input type="checkbox" class="form-check-input" id="tickets-select-all" aria-label="Select all tickets on this page"></th>' : ''}
                            ${th('Ticket #', 'ticket_number')}
                            <th class="ticket-subject-column">Subject / Customer</th>
                            ${th('Status', 'status')}
                            ${th('Priority', 'priority')}
                            ${th('Tags', null)}
                            <th class="text-center">Comments</th>
                            ${th('Assigned To', null)}
                            ${th('Created', 'created_at')}
                            ${th('Updated', 'updated_at')}
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>`);

        // Sortable column headers
        $('#tickets-table-wrap th[data-sort]').on('click', (e) => {
            if (this._bulkBusy) return;
            const field = $(e.currentTarget).data('sort');
            if (this._sort === field) {
                this._dir = this._dir === 'asc' ? 'desc' : 'asc';
            } else {
                this._sort = field;
                this._dir  = 'desc';
            }
            this.loadTickets(1);
        });

        // Row click
        $('.ticket-row').on('click', function(e) {
            if ($(e.target).closest('.ticket-selection').length) return;
            App.navigate('/tickets/' + $(this).data('id'));
        });
        $('.ticket-select').on('change', (e) => {
            const id = Number(e.target.value);
            if (e.target.checked) this._selected.add(id);
            else this._selected.delete(id);
            this.updateBulkControls();
        });
        $('#tickets-select-all').on('change', (e) => {
            this._selected = new Set(e.target.checked ? this._tickets.map(t => Number(t.id)) : []);
            this.updateBulkControls();
        });
        $('#tickets-clear-selection').on('click', () => {
            this._selected.clear();
            this.updateBulkControls();
        });
        $('#tickets-bulk-resolve').on('click', () => this.runBulkAction('resolve'));
        $('#tickets-bulk-delete').on('click', () => this.runBulkAction('delete'));
    },

    updateBulkControls() {
        const count = this._selected.size;
        $('#ticket-selection-count').text(`${count} selected on this page`);
        $('#tickets-bulk-resolve, #tickets-bulk-delete, #tickets-clear-selection').prop('disabled', !count || this._bulkBusy);
        $('#tickets-select-all').prop('checked', count > 0 && count === this._tickets.length)
            .prop('indeterminate', count > 0 && count < this._tickets.length);
        $('.ticket-select').each((_, input) => { input.checked = this._selected.has(Number(input.value)); });
    },

    async runBulkAction(action) {
        if (this._bulkBusy || this._loading || !this._selected.size) return;
        if (!['resolve', 'delete'].includes(action)) return;
        const permission = action === 'delete' ? 'can_delete_tickets' : 'can_close_tickets';
        if (!API.isAdmin() && !API.can(permission)) return;
        const view = this._view;
        const root = document.getElementById('tickets-table-wrap');
        const stillHere = () => view === this._view && root === document.getElementById('tickets-table-wrap');
        const tickets = this._tickets.filter(t => this._selected.has(Number(t.id)));
        // Delete selected children first, avoiding false failures after a parent's cascade.
        const depth = (ticket) => {
            const seen = new Set();
            while (ticket?.parent_ticket_id && !seen.has(Number(ticket.id))) {
                seen.add(Number(ticket.id));
                ticket = this._tickets.find(t => Number(t.id) === Number(ticket.parent_ticket_id));
            }
            return seen.size;
        };
        if (action === 'delete') tickets.sort((a, b) => depth(b) - depth(a));
        this._bulkBusy = true;
        this.updateBulkControls();
        $('#tickets-table-wrap input, #filter-status, #filter-priority, #filter-agent, #filter-tag, #filter-q, #filter-reset').prop('disabled', true);
        const failures = [];
        let succeeded = 0;
        let confirmed = false;
        try {
            const warning = action === 'delete'
                ? `Delete ${tickets.length} selected ticket(s)? Their child tickets (including those outside this page) and attachments will also be deleted. This cannot be undone.`
                : `Resolve ${tickets.length} selected ticket(s)? Normal ticket status-change notifications will apply.`;
            confirmed = await App.confirm(warning, action === 'delete' ? 'Delete Selected Tickets' : 'Resolve Selected Tickets');
            if (!confirmed || !stillHere()) return;
            for (const [index, ticket] of tickets.entries()) {
                if (!stillHere()) break;
                $('#tickets-bulk-result').text(`Processing ${index + 1} of ${tickets.length}...`);
                try {
                    if (action === 'delete') await API.delete('/tickets/' + Number(ticket.id));
                    else await API.post('/tickets/' + Number(ticket.id) + '/status', { status: 'resolved' });
                    succeeded++;
                } catch (e) {
                    failures.push(`${ticket.ticket_number}: ${e.message || 'Request failed'}`);
                }
            }
        } finally {
            if (stillHere()) {
                this._bulkBusy = false;
                $('#tickets-table-wrap input, #filter-status, #filter-priority, #filter-agent, #filter-tag, #filter-q, #filter-reset').prop('disabled', false);
                this.updateBulkControls();
            }
        }
        if (!stillHere() || !confirmed) return;
        await this.loadTickets(this._page || 1);
        if (!stillHere()) return;
        const summary = `${succeeded} selected ticket(s) ${action === 'delete' ? 'deleted' : 'resolved'}.`;
        App.toast(summary, failures.length ? 'warning' : 'success');
        if (failures.length) {
            await App.confirm(`${summary}\n\n${failures.length} request(s) failed. Check ticket status before retrying:\n${failures.join('\n')}`, 'Bulk Action Results');
        }
        if (typeof Notifications !== 'undefined') Notifications.refreshCountersOnly();
    },

    renderPagination(meta, currentPage) {
        if (!meta.last_page || meta.last_page <= 1) {
            $('#tickets-pagination').empty();
            return;
        }

        let pages = '';
        for (let i = 1; i <= meta.last_page; i++) {
            if (Math.abs(i - currentPage) > 3 && i !== 1 && i !== meta.last_page) {
                if (i === currentPage - 4 || i === currentPage + 4) pages += '<li class="page-item disabled"><span class="page-link">…</span></li>';
                continue;
            }
            pages += `<li class="page-item ${i === currentPage ? 'active' : ''}">
                <a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
        }

        $('#tickets-pagination').html(`
            <nav><ul class="pagination pagination-sm justify-content-center">
                <li class="page-item ${currentPage <= 1 ? 'disabled' : ''}">
                    <a class="page-link" href="#" data-page="${currentPage - 1}">‹</a>
                </li>
                ${pages}
                <li class="page-item ${currentPage >= meta.last_page ? 'disabled' : ''}">
                    <a class="page-link" href="#" data-page="${currentPage + 1}">›</a>
                </li>
            </ul></nav>`);

        $('#tickets-pagination').off('click').on('click', 'a[data-page]', (e) => {
            e.preventDefault();
            this.loadTickets(parseInt($(e.currentTarget).data('page')));
        });
    }
};
