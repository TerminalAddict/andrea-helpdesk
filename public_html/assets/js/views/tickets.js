/**
 * Tickets List View
 */
const TicketsView = {
    agents: [],
    tags: [],

    render() {
        return `
        <div class="container-fluid p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="mb-0"><i class="bi bi-ticket-perforated me-2"></i>Tickets</h4>
                <a href="#/tickets/new" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg me-1"></i>New Ticket
                </a>
            </div>

            <!-- Filters -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body py-2">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-2">
                            <select class="form-select form-select-sm" id="filter-status">
                                <option value="">All Statuses</option>
                                <option value="open" selected>Open</option>
                                <option value="pending">Pending</option>
                                <option value="resolved">Resolved</option>
                                <option value="closed">Closed</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select class="form-select form-select-sm" id="filter-priority">
                                <option value="">All Priorities</option>
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
            <div class="card border-0 shadow-sm">
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
            $('#filter-status').val('open');
            $('#filter-priority, #filter-agent, #filter-tag').val('');
            $('#filter-q').val('');
            this.loadTickets();
        });

        this.loadTickets();
    },

    async loadTickets(page = 1) {
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
            this.renderTable(res.data || [], res.meta || {});
            this.renderPagination(res.meta || {}, page);
        } catch (e) {
            $('#tickets-table-wrap').html('<p class="text-danger p-3">Failed to load tickets: ' + App.escapeHtml(e.message) + '</p>');
        }
    },

    renderTable(tickets, meta) {
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
            <tr class="ticket-row${child ? ' table-light ticket-child-row' : ''}" data-id="${t.id}" style="cursor:pointer;">
                <td>
                    ${child ? '<span class="text-muted me-1" style="padding-left:1rem;">↳</span>' : ''}
                    <span class="font-monospace small fw-semibold">${App.escapeHtml(t.ticket_number)}</span>
                </td>
                <td>
                    <div>${App.escapeHtml(t.subject)}</div>
                    <div class="small text-muted">${App.escapeHtml(t.customer_name || '')} ${t.customer_email ? '&lt;' + App.escapeHtml(t.customer_email) + '&gt;' : ''}</div>
                </td>
                <td>${App.statusBadge(t.status)}</td>
                <td>${App.priorityBadge(t.priority)}</td>
                <td>${t.tag_names ? t.tag_names.split(',').map(tag => `<span class="badge bg-secondary me-1">${App.escapeHtml(tag)}</span>`).join('') : ''}</td>
                <td class="small text-center text-muted">${t.reply_count > 0 ? t.reply_count : '–'}</td>
                <td class="small">${t.agent_name ? App.escapeHtml(t.agent_name) : '<em class="text-muted">Unassigned</em>'}</td>
                <td class="small text-muted text-nowrap">${App.formatDate(t.created_at)}</td>
                <td class="small text-muted text-nowrap">${App.formatDate(t.updated_at)}</td>
            </tr>`).join('');

        const total = meta.total || 0;
        $('#tickets-table-wrap').html(`
            <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom bg-light">
                <span class="small text-muted">${total} ticket${total !== 1 ? 's' : ''}</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:140px;">Ticket #</th>
                            <th>Subject / Customer</th>
                            <th>Status</th>
                            <th>Priority</th>
                            <th>Tags</th>
                            <th class="text-center">Comments</th>
                            <th>Assigned To</th>
                            <th>Created</th>
                            <th>Updated</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>`);

        // Row click
        $('.ticket-row').on('click', function() {
            App.navigate('/tickets/' + $(this).data('id'));
        });
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

        $('#tickets-pagination').on('click', 'a[data-page]', (e) => {
            e.preventDefault();
            this.loadTickets(parseInt($(e.currentTarget).data('page')));
        });
    }
};
