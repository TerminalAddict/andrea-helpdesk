/**
 * Dashboard View
 */
const DashboardView = {
    render() {
        return `
        <div class="container-fluid p-4">
            <h4 class="mb-4"><i class="bi bi-speedometer2 me-2"></i>Dashboard</h4>

            <div class="row g-3 mb-4" id="summary-cards">
                ${['Open','Pending','Resolved','Closed'].map(s => `
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body text-center py-3">
                            <div class="fs-1 fw-bold text-primary" id="stat-${s.toLowerCase()}">–</div>
                            <div class="text-muted small">${s} Tickets</div>
                        </div>
                    </div>
                </div>`).join('')}
            </div>

            <div class="mb-3">
                <div class="input-group input-group-lg shadow-sm">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="search" class="form-control border-start-0 ps-0" id="dash-search" placeholder="Search tickets by subject, email, message content or comments…" autocomplete="off">
                    <div class="input-group-text bg-white border-start-0 text-muted small d-none" id="dash-search-spinner">
                        <div class="spinner-border spinner-border-sm"></div>
                    </div>
                </div>
                <div id="dash-search-results" class="card border-0 shadow mt-1 d-none" style="max-height:420px;overflow-y:auto;"></div>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white fw-semibold">
                            <i class="bi bi-person-check me-2"></i>My Assigned Tickets
                        </div>
                        <div class="card-body p-0">
                            <div id="my-tickets-table">
                                <div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm"></div></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white fw-semibold">
                            <i class="bi bi-clock-history me-2"></i>Recently Updated
                        </div>
                        <div class="card-body p-0">
                            <div id="recent-tickets-table">
                                <div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm"></div></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mt-1">
                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white d-flex align-items-center gap-2 flex-wrap">
                            <span class="fw-semibold me-auto"><i class="bi bi-inbox me-2"></i>All Open Tickets</span>
                            <select class="form-select form-select-sm w-auto" id="dash-filter-tag">
                                <option value="">All Tags</option>
                            </select>
                            <select class="form-select form-select-sm w-auto" id="dash-filter-priority">
                                <option value="">All Priorities</option>
                                <option value="urgent">Urgent</option>
                                <option value="high">High</option>
                                <option value="normal">Normal</option>
                                <option value="low">Low</option>
                            </select>
                        </div>
                        <div class="card-body p-0">
                            <div id="all-open-tickets-table">
                                <div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm"></div></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>`;
    },

    async init() {
        // Load summary
        try {
            const res  = await API.get('/reports/summary', { from: new Date().toISOString().slice(0,10), to: new Date().toISOString().slice(0,10) });
            const data = res.data;
            $('#stat-open').text(data.open || 0);
            $('#stat-pending').text(data.pending || 0);
            $('#stat-resolved').text(data.resolved || 0);
            $('#stat-closed').text(data.closed || 0);
        } catch (e) {}

        const pageSize = parseInt(API.currentUser?.page_size) || 20;

        // My tickets
        try {
            const res = await API.get('/tickets', { assigned_to: API.currentUser.id, status: 'open', per_page: pageSize });
            this.renderTable('#my-tickets-table', res.data || [], res.meta || {}, `#/tickets?status=open&assigned_to=${API.currentUser.id}`);
        } catch (e) {
            $('#my-tickets-table').html('<p class="text-muted p-3">No tickets assigned to you.</p>');
        }

        // Recent tickets
        try {
            const res = await API.get('/tickets', { per_page: pageSize, sort: 'updated_at', dir: 'desc' });
            this.renderTable('#recent-tickets-table', res.data || [], res.meta || {}, `#/tickets?sort=updated_at&dir=desc`);
        } catch (e) {
            $('#recent-tickets-table').html('<p class="text-muted p-3">No recent tickets.</p>');
        }

        // Quick search
        let searchTimer;
        $('#dash-search').on('input', () => {
            clearTimeout(searchTimer);
            const q = $('#dash-search').val().trim();
            if (!q) { $('#dash-search-results').addClass('d-none').empty(); return; }
            searchTimer = setTimeout(() => this.runSearch(q), 350);
        });
        $(document).on('click.dashsearch', (e) => {
            if (!$(e.target).closest('#dash-search, #dash-search-results').length) {
                $('#dash-search-results').addClass('d-none');
            }
        });
        $('#dash-search').on('focus', () => {
            if ($('#dash-search-results').children().length) $('#dash-search-results').removeClass('d-none');
        });

        // Tags for filter
        try {
            const res = await API.get('/tags');
            (res.data || []).forEach(t => {
                $('#dash-filter-tag').append(`<option value="${t.id}">${App.escapeHtml(t.name)}</option>`);
            });
        } catch (e) {}

        // All open tickets
        await this.loadAllOpen();
        $('#dash-filter-tag, #dash-filter-priority').on('change', () => this.loadAllOpen());
    },

    async loadAllOpen() {
        $('#all-open-tickets-table').html('<div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm"></div></div>');
        try {
            const pageSize = parseInt(API.currentUser?.page_size) || 20;
            const params   = { status: 'open', per_page: pageSize, sort: 'updated_at', dir: 'desc' };
            const tag_id   = $('#dash-filter-tag').val();
            const priority = $('#dash-filter-priority').val();
            if (tag_id)   params.tag_id   = tag_id;
            if (priority) params.priority = priority;
            const res   = await API.get('/tickets', params);
            const total = res.meta?.total || 0;
            const viewAllParams = new URLSearchParams({ status: 'open', sort: 'updated_at', dir: 'desc' });
            if (tag_id)   viewAllParams.set('tag_id',   tag_id);
            if (priority) viewAllParams.set('priority', priority);
            this.renderAllOpenTable(res.data || [], total, `#/tickets?${viewAllParams}`);
        } catch (e) {
            $('#all-open-tickets-table').html('<p class="text-muted p-3 mb-0">Failed to load tickets.</p>');
        }
    },

    async runSearch(q) {
        $('#dash-search-spinner').removeClass('d-none');
        try {
            const res     = await API.get('/tickets', { q, per_page: 20, sort: 'updated_at', dir: 'desc' });
            const tickets = res.data || [];
            const $box    = $('#dash-search-results').empty().removeClass('d-none');

            if (!tickets.length) {
                $box.html('<p class="text-muted p-3 mb-0 small">No tickets found for <strong>' + App.escapeHtml(q) + '</strong>.</p>');
                return;
            }

            const rows = tickets.map(t => {
                const tags = t.tag_names
                    ? t.tag_names.split(',').map(tag => `<span class="badge bg-secondary me-1">${App.escapeHtml(tag)}</span>`).join('')
                    : '';
                return `<a class="d-flex align-items-start gap-3 p-3 text-decoration-none text-dark border-bottom dash-search-item"
                           href="#/tickets/${t.id}" style="cursor:pointer;">
                    <div class="flex-grow-1 overflow-hidden">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <span class="font-monospace small text-muted">${App.escapeHtml(t.ticket_number)}</span>
                            ${App.statusBadge(t.status)}
                            ${App.priorityBadge(t.priority)}
                            ${tags}
                        </div>
                        <div class="fw-semibold text-truncate mt-1">${App.escapeHtml(t.subject)}</div>
                        <div class="small text-muted">${App.escapeHtml(t.customer_name || '')} ${t.customer_email ? '&lt;' + App.escapeHtml(t.customer_email) + '&gt;' : ''}</div>
                    </div>
                    <div class="small text-muted text-nowrap">${App.formatDate(t.updated_at)}</div>
                </a>`;
            }).join('');

            $box.html(rows);
            $box.find('.dash-search-item').on('click', () => {
                $('#dash-search-results').addClass('d-none');
                $('#dash-search').val('');
            });
        } catch (e) {
            $('#dash-search-results').html('<p class="text-danger p-3 mb-0 small">' + App.escapeHtml(e.message) + '</p>').removeClass('d-none');
        } finally {
            $('#dash-search-spinner').addClass('d-none');
        }
    },

    renderAllOpenTable(tickets, total = 0, viewAllHref = '#/tickets') {
        if (!tickets.length) {
            $('#all-open-tickets-table').html('<p class="text-muted p-3 mb-0">No open tickets match the selected filters.</p>');
            return;
        }
        const rows = tickets.map(t => {
            const tags = t.tag_names
                ? t.tag_names.split(',').map(tag => `<span class="badge bg-secondary me-1">${App.escapeHtml(tag)}</span>`).join('')
                : '';
            return `
            <tr style="cursor:pointer;" onclick="App.navigate('/tickets/${t.id}')">
                <td><span class="font-monospace small">${App.escapeHtml(t.ticket_number)}</span></td>
                <td class="text-truncate" style="max-width:200px;">${App.escapeHtml(t.subject)}</td>
                <td>${App.priorityBadge(t.priority)}</td>
                <td>${tags}</td>
                <td class="small text-muted">${App.escapeHtml(t.agent_name || '—')}</td>
                <td class="small text-muted text-nowrap">${App.formatDate(t.updated_at)}</td>
            </tr>`;
        }).join('');
        const showing = tickets.length;
        const footer  = total > showing
            ? `<div class="px-3 py-2 border-top bg-light d-flex justify-content-between align-items-center">
                   <span class="small text-muted">Showing ${showing} of ${total}</span>
                   <a href="${viewAllHref}" class="btn btn-sm btn-outline-primary">View all ${total} tickets →</a>
               </div>` : '';
        $('#all-open-tickets-table').html(`
            <table class="table table-hover table-sm mb-0">
                <thead class="table-light">
                    <tr><th>Ticket</th><th>Subject</th><th>Priority</th><th>Tags</th><th>Assigned To</th><th>Updated</th></tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>${footer}`);
    },

    renderTable(selector, tickets, meta = {}, viewAllHref = null) {
        if (!tickets.length) {
            $(selector).html('<p class="text-muted p-3 mb-0">No tickets to show.</p>');
            return;
        }

        const rows = tickets.map(t => {
            const tags = t.tag_names
                ? t.tag_names.split(',').map(tag => `<span class="badge bg-secondary me-1">${App.escapeHtml(tag)}</span>`).join('')
                : '';
            return `
            <tr style="cursor:pointer;" onclick="App.navigate('/tickets/${t.id}')">
                <td><span class="font-monospace small">${App.escapeHtml(t.ticket_number)}</span></td>
                <td class="text-truncate" style="max-width:160px;">${App.escapeHtml(t.subject)}</td>
                <td>${App.statusBadge(t.status)}</td>
                <td>${tags}</td>
                <td class="small text-muted">${App.formatDate(t.updated_at)}</td>
            </tr>`;
        }).join('');

        const total   = meta.total || 0;
        const showing = tickets.length;
        const footer  = viewAllHref && total > showing
            ? `<div class="px-3 py-2 border-top bg-light d-flex justify-content-between align-items-center">
                   <span class="small text-muted">Showing ${showing} of ${total}</span>
                   <a href="${viewAllHref}" class="btn btn-sm btn-outline-primary">View all ${total} →</a>
               </div>` : '';

        $(selector).html(`
            <table class="table table-hover table-sm mb-0">
                <thead class="table-light">
                    <tr><th>Ticket</th><th>Subject</th><th>Status</th><th>Tags</th><th>Updated</th></tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>${footer}`);
    }
};
