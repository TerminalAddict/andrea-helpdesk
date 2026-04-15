/**
 * Reports View
 */
const ReportsView = {
    render() {
        const now = new Date();
        const today = now.toISOString().slice(0, 10);
        const monthStart = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().slice(0, 10);
        return `
        <div class="container-fluid terminal-screen terminal-screen-reports p-4">
            <h4 class="terminal-heading mb-4"><i class="bi bi-bar-chart me-2"></i>Reports</h4>

            <!-- Date range filter -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body py-2">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-2">
                            <label class="form-label small mb-1">From</label>
                            <input type="date" class="form-control form-control-sm" id="rpt-from" value="${monthStart}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">To</label>
                            <input type="date" class="form-control form-control-sm" id="rpt-to" value="${today}">
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-primary btn-sm" id="rpt-run">
                                <i class="bi bi-play me-1"></i>Run Report
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div id="reports-content">
                <div class="text-center py-5 text-muted">
                    <div class="spinner-border"></div><p class="mt-2">Loading…</p>
                </div>
            </div>
        </div>`;
    },

    async init() {
        $('#rpt-run').on('click', () => this.load());
        await this.load();
    },

    async load() {
        const from = $('#rpt-from').val();
        const to   = $('#rpt-to').val();

        $('#reports-content').html('<div class="text-center py-5 text-muted"><div class="spinner-border"></div></div>');

        try {
            const [summaryRes, agentRes, volumeRes, timeRes] = await Promise.all([
                API.get('/reports/activity-summary',  { from, to }),
                API.get('/reports/activity-by-agent', { from, to }),
                API.get('/reports/activity-volume',   { from, to, group_by: 'day' }),
                API.get('/reports/time-to-close', { from, to }),
            ]);

            this.render_results(
                summaryRes.data || {},
                agentRes.data  || [],
                volumeRes.data || [],
                timeRes.data   || {}
            );
        } catch (e) {
            $('#reports-content').html('<div class="alert alert-danger">' + App.escapeHtml(e.message) + '</div>');
        }
    },

    render_results(summary, byAgent, volume, timeToClose) {
        const statuses = [
            { key: 'new', label: 'New', color: 'text-info' },
            { key: 'waiting_for_reply', label: 'Waiting for Reply', color: 'text-danger' },
            { key: 'pending', label: 'Pending', color: 'text-warning' },
            { key: 'replied', label: 'Replied', color: 'text-success' },
            { key: 'overdue', label: 'Overdue', color: 'text-danger' },
        ];
        const summaryCards = statuses.map(s => `
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body text-center py-3">
                        <div class="fs-2 fw-bold ${s.color}">${summary[s.key] || 0}</div>
                        <div class="text-muted small">${s.label}</div>
                    </div>
                </div>
            </div>`).join('');

        // Volume table
        const volRows = volume.map(v =>
            `<tr>
                <td>${App.escapeHtml(v.period)}</td>
                <td>${v.created || 0}</td>
                <td>${v.customer_replies || 0}</td>
                <td>${v.agent_replies || 0}</td>
                <td>${v.internal_notes || 0}</td>
                <td>${v.system_events || 0}</td>
                <td>${v.total || 0}</td>
            </tr>`
        ).join('') || '<tr><td colspan="7" class="text-muted text-center">No data</td></tr>';

        // By-agent table
        const agentRows = byAgent.map(a =>
            `<tr>
                <td>${App.escapeHtml(a.agent_name || 'Unassigned')}</td>
                <td>${a.assigned || 0}</td>
                <td>${a.created || 0}</td>
                <td>${a.replies || 0}</td>
                <td>${a.notes || 0}</td>
                <td>${a.resolved || 0}</td>
                <td>${a.closed || 0}</td>
            </tr>`
        ).join('') || '<tr><td colspan="7" class="text-muted text-center">No data</td></tr>';

        const avgClose = timeToClose.avg_minutes
            ? this.formatDuration(timeToClose.avg_minutes / 60)
            : '–';

        const html = `
        <div class="row g-3 mb-4">
            ${summaryCards}
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body text-center py-3">
                        <div class="fs-2 fw-bold text-body">${summary.ticket_count || 0}</div>
                        <div class="text-muted small">Tickets In Scope</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white fw-semibold py-2">
                        <i class="bi bi-clock me-2"></i>Avg. Time to Close
                    </div>
                    <div class="card-body text-center py-4">
                        <div class="fs-2 fw-bold text-success">${avgClose}</div>
                        <div class="text-muted small">${timeToClose.count || 0} ticket${timeToClose.count !== 1 ? 's' : ''} closed in range</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-semibold py-2">
                        <i class="bi bi-graph-up me-2"></i>Ticket Activity (Daily)
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead class="table-light"><tr><th>Date</th><th>Created</th><th>Customer Replies</th><th>Agent Replies</th><th>Notes</th><th>System</th><th>Total</th></tr></thead>
                                <tbody>${volRows}</tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-semibold py-2">
                        <i class="bi bi-person-check me-2"></i>Agent Activity
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead class="table-light">
                                    <tr><th>Agent</th><th>Assigned</th><th>Created</th><th>Replies</th><th>Notes</th><th>Resolved</th><th>Closed</th></tr>
                                </thead>
                                <tbody>${agentRows}</tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>`;

        $('#reports-content').html(html);
    },

    formatDuration(hours) {
        if (hours < 1) return Math.round(hours * 60) + 'm';
        if (hours < 24) return hours.toFixed(1) + 'h';
        return (hours / 24).toFixed(1) + 'd';
    }
};
