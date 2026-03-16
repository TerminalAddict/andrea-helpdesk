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
        } catch (e) {
            // Fallback: direct count
            try {
                const res = await API.get('/tickets', { per_page: 1 });
            } catch (e2) {}
        }

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
    },

    renderTable(selector, tickets) {
        if (!tickets.length) {
            $(selector).html('<p class="text-muted p-3 mb-0">No tickets to show.</p>');
            return;
        }

        const rows = tickets.map(t => `
            <tr style="cursor:pointer;" onclick="App.navigate('/tickets/${t.id}')">
                <td><span class="font-monospace small">${App.escapeHtml(t.ticket_number)}</span></td>
                <td class="text-truncate" style="max-width:200px;">${App.escapeHtml(t.subject)}</td>
                <td>${App.statusBadge(t.status)}</td>
                <td class="small text-muted">${App.formatDate(t.updated_at)}</td>
            </tr>`).join('');

        $(selector).html(`
            <table class="table table-hover table-sm mb-0">
                <thead class="table-light">
                    <tr><th>Ticket</th><th>Subject</th><th>Status</th><th>Updated</th></tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>`);
    }
};
