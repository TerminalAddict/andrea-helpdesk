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

        // My tickets
        try {
            const res = await API.get('/tickets', { assigned_to: API.currentUser.id, status: 'open', per_page: 10 });
            this.renderTable('#my-tickets-table', res.data || []);
        } catch (e) {
            $('#my-tickets-table').html('<p class="text-muted p-3">No tickets assigned to you.</p>');
        }

        // Recent tickets
        try {
            const res = await API.get('/tickets', { per_page: 10, sort: 'updated_at', dir: 'desc' });
            this.renderTable('#recent-tickets-table', res.data || []);
        } catch (e) {
            $('#recent-tickets-table').html('<p class="text-muted p-3">No recent tickets.</p>');
        }

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
            const params = { status: 'open', per_page: 50, sort: 'updated_at', dir: 'desc' };
            const tag_id   = $('#dash-filter-tag').val();
            const priority = $('#dash-filter-priority').val();
            if (tag_id)   params.tag_id   = tag_id;
            if (priority) params.priority = priority;
            const res = await API.get('/tickets', params);
            this.renderAllOpenTable(res.data || []);
        } catch (e) {
            $('#all-open-tickets-table').html('<p class="text-muted p-3 mb-0">Failed to load tickets.</p>');
        }
    },

    renderAllOpenTable(tickets) {
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
        $('#all-open-tickets-table').html(`
            <table class="table table-hover table-sm mb-0">
                <thead class="table-light">
                    <tr><th>Ticket</th><th>Subject</th><th>Priority</th><th>Tags</th><th>Assigned To</th><th>Updated</th></tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>`);
    },

    renderTable(selector, tickets) {
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

        $(selector).html(`
            <table class="table table-hover table-sm mb-0">
                <thead class="table-light">
                    <tr><th>Ticket</th><th>Subject</th><th>Status</th><th>Tags</th><th>Updated</th></tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>`);
    }
};
