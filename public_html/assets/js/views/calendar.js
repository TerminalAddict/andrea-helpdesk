/**
 * Andrea Helpdesk — Calendar View
 * Displays tickets with due dates in a browsable monthly calendar.
 * Provides iCal subscription URLs for Outlook, Google Calendar, etc.
 */
const CalendarView = {
    year:    new Date().getFullYear(),
    month:   new Date().getMonth(), // 0-indexed
    events:  [],
    _loadGen: 0, // incremented on each navigation to discard stale responses

    render() {
        return `
        <div class="container-fluid terminal-screen terminal-screen-calendar py-3">
            <div class="terminal-screen-header d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                <h4 class="mb-0"><i class="bi bi-calendar3 me-2"></i>Calendar</h4>
                <button class="btn btn-outline-success btn-sm" id="cal-subscribe-btn">
                    <i class="bi bi-calendar-plus me-1"></i>Subscribe
                </button>
            </div>

            <!-- Calendar card -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex align-items-center justify-content-between py-2">
                    <div class="d-flex gap-2 align-items-center">
                        <button class="btn btn-sm btn-outline-secondary" id="cal-prev">
                            <i class="bi bi-chevron-left"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-secondary" id="cal-next">
                            <i class="bi bi-chevron-right"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-primary" id="cal-today">Today</button>
                    </div>
                    <h5 class="mb-0 fw-semibold" id="cal-title"></h5>
                    <div style="width:160px;"></div><!-- spacer -->
                </div>
                <div class="card-body p-0">
                    <div id="cal-grid-wrap" style="overflow-x:auto;">
                        <table class="table table-bordered mb-0 w-100" id="cal-grid" style="table-layout:fixed;min-width:700px;">
                            <thead class="table-light">
                                <tr>
                                    <th class="text-center small py-2">Mon</th>
                                    <th class="text-center small py-2">Tue</th>
                                    <th class="text-center small py-2">Wed</th>
                                    <th class="text-center small py-2">Thu</th>
                                    <th class="text-center small py-2">Fri</th>
                                    <th class="text-center small py-2 text-muted">Sat</th>
                                    <th class="text-center small py-2 text-muted">Sun</th>
                                </tr>
                            </thead>
                            <tbody id="cal-body">
                                <tr><td colspan="7" class="text-center py-5 text-muted">
                                    <div class="spinner-border spinner-border-sm me-2"></div>Loading...
                                </td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Legend -->
            <div class="mt-2 d-flex gap-3 flex-wrap">
                <span class="small"><span class="badge bg-danger me-1">●</span>Urgent</span>
                <span class="small"><span class="badge bg-warning text-dark me-1">●</span>High</span>
                <span class="small"><span class="badge bg-primary me-1">●</span>Normal</span>
                <span class="small"><span class="badge bg-secondary me-1">●</span>Low</span>
                <span class="small text-muted ms-2"><i class="bi bi-exclamation-circle text-danger me-1"></i>Overdue</span>
            </div>
        </div>

        <!-- Subscribe modal -->
        <div class="modal fade" id="subscribeModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-calendar-plus me-2"></i>Subscribe to Calendar</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body" id="subscribe-modal-body">
                        <div class="text-center py-3">
                            <div class="spinner-border spinner-border-sm text-primary"></div>
                            <div class="small text-muted mt-2">Generating your personal subscription link…</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>`;
    },

    async init() {
        $('#cal-prev').on('click', () => this.navigate(-1));
        $('#cal-next').on('click', () => this.navigate(1));
        $('#cal-today').on('click', () => {
            this.year  = new Date().getFullYear();
            this.month = new Date().getMonth();
            this.loadMonth();
        });
        $('#cal-subscribe-btn').on('click', () => this.openSubscribeModal());
        await this.loadMonth();
    },

    navigate(delta) {
        this.month += delta;
        if (this.month > 11) { this.month = 0;  this.year++; }
        if (this.month < 0)  { this.month = 11; this.year--; }
        this.loadMonth();
    },

    async loadMonth() {
        const gen = ++this._loadGen;
        const y = this.year, m = this.month;
        const from    = new Date(y, m, 1);
        const to      = new Date(y, m + 1, 0);
        const fromStr = this.isoDate(from);
        const toStr   = this.isoDate(to);

        $('#cal-title').text(from.toLocaleDateString(undefined, { month: 'long', year: 'numeric' }));
        $('#cal-body').html('<tr><td colspan="7" class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm me-2"></div>Loading…</td></tr>');

        try {
            const res = await API.get('/calendar/events', { from: fromStr, to: toStr });
            if (gen !== this._loadGen) return; // stale — a newer navigation fired, discard
            this.events = res.data || [];
        } catch (e) {
            if (gen !== this._loadGen) return;
            App.toast('Failed to load calendar events', 'error');
            this.events = [];
        }

        this.renderGrid(y, m);
    },

    renderGrid(y, m) {
        // Build a map: date string → array of events
        const byDate = {};
        for (const ev of this.events) {
            const start = ev.due_at.split(' ')[0]; // "YYYY-MM-DD"
            const end   = ev.due_end ? ev.due_end.split(' ')[0] : start;

            // Expand multi-day events across all days they span
            let cur = new Date(start + 'T00:00:00');
            const endD = new Date(end + 'T00:00:00');
            while (cur <= endD) {
                const key = this.isoDate(cur);
                (byDate[key] = byDate[key] || []).push(ev);
                cur.setDate(cur.getDate() + 1);
            }
        }

        // Month grid — weeks start Monday (ISO)
        const firstDay = new Date(y, m, 1);
        const lastDay  = new Date(y, m + 1, 0);
        const today    = this.isoDate(new Date());

        // dayOfWeek: Mon=0 … Sun=6
        const startPad = (firstDay.getDay() + 6) % 7; // days to pad before 1st

        let rows = '';
        let row  = '<tr>';
        let col  = 0;

        // Padding cells before the 1st
        for (let p = 0; p < startPad; p++) {
            row += '<td class="cal-cell bg-light" style="height:100px;vertical-align:top;"></td>';
            col++;
        }

        for (let d = 1; d <= lastDay.getDate(); d++) {
            const dateStr = `${y}-${String(m + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
            const isToday = dateStr === today;
            const dayEvs  = byDate[dateStr] || [];
            const isWeekend = col >= 5;

            const evHtml = dayEvs.slice(0, 4).map(ev => {
                const isMulti = ev.due_end && ev.due_end.split(' ')[0] !== ev.due_at.split(' ')[0];
                const now     = new Date();
                const dueDate = new Date((ev.due_at.replace(' ', 'T')));
                const overdue = !ev.due_all_day && dueDate < now && !['resolved','closed'].includes(ev.status);
                const badgeClass = overdue ? 'bg-danger' :
                    ({ urgent: 'bg-danger', high: 'bg-warning text-dark', normal: 'bg-primary', low: 'bg-secondary' }[ev.priority] || 'bg-secondary');
                const timeStr  = ev.due_all_day ? '' :
                    new Date(ev.due_at.replace(' ', 'T')).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
                return `<a href="#/tickets/${ev.id}" class="d-block text-decoration-none mb-1 cal-event" title="${App.escapeHtml('[' + ev.ticket_number + '] ' + ev.subject)}">
                    <span class="badge ${badgeClass} w-100 text-start text-truncate" style="font-weight:400;font-size:.7rem;">
                        ${overdue ? '<i class="bi bi-exclamation-circle me-1"></i>' : ''}${isMulti ? '<i class="bi bi-arrow-right me-1"></i>' : ''}${timeStr ? App.escapeHtml(timeStr) + ' ' : ''}${App.escapeHtml(ev.ticket_number)}
                    </span>
                </a>`;
            }).join('');

            const moreCount = dayEvs.length - 4;
            const moreHtml  = moreCount > 0
                ? `<span class="small text-muted">+${moreCount} more</span>`
                : '';

            row += `<td class="cal-cell p-1 ${isWeekend ? 'bg-light' : ''}" style="height:100px;vertical-align:top;${isToday ? 'background:#e8f0fe!important;' : ''}">
                <div class="d-flex justify-content-between align-items-start">
                    <span class="cal-day-num small fw-semibold ${isToday ? 'text-primary' : isWeekend ? 'text-muted' : ''}" style="font-size:.8rem;">
                        ${isToday ? `<span class="badge bg-primary rounded-circle" style="width:22px;height:22px;line-height:14px;font-size:.75rem;">${d}</span>` : d}
                    </span>
                </div>
                <div class="mt-1">${evHtml}${moreHtml}</div>
            </td>`;
            col++;

            if (col === 7) {
                row += '</tr>';
                rows += row;
                row = '<tr>';
                col = 0;
            }
        }

        // Pad trailing cells
        if (col > 0) {
            while (col < 7) {
                row += '<td class="cal-cell bg-light" style="height:100px;"></td>';
                col++;
            }
            row += '</tr>';
            rows += row;
        }

        $('#cal-body').html(rows || '<tr><td colspan="7" class="text-center py-4 text-muted small">No tickets with due dates this month.</td></tr>');
    },

    async openSubscribeModal() {
        const modal = new bootstrap.Modal(document.getElementById('subscribeModal'));
        modal.show();
        $('#subscribe-modal-body').html(`
            <div class="text-center py-3">
                <div class="spinner-border spinner-border-sm text-primary"></div>
                <div class="small text-muted mt-2">Generating your personal subscription link…</div>
            </div>`);
        try {
            const res = await API.get('/calendar/token');
            const d   = res.data;
            $('#subscribe-modal-body').html(`
                <p class="small text-muted mb-3">
                    This is your personal calendar URL. It includes a secure token linked to your account.
                    Anyone with this URL can view your tickets' due dates — keep it private.
                </p>

                <label class="form-label small fw-semibold">iCal / Subscription URL</label>
                <div class="input-group mb-3">
                    <input type="text" class="form-control form-control-sm font-monospace small" id="ical-url-input"
                        value="${App.escapeHtml(d.ical_url)}" readonly>
                    <button class="btn btn-outline-secondary btn-sm" id="btn-copy-ical">
                        <i class="bi bi-clipboard"></i>
                    </button>
                </div>

                <p class="small fw-semibold mb-2">Subscribe with your calendar app:</p>
                <div class="d-flex flex-wrap gap-2">
                    <a href="${App.escapeHtml(d.webcal_url)}" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-apple me-1"></i>Apple Calendar / Outlook
                    </a>
                    <a href="https://calendar.google.com/calendar/r/settings/addbyurl?url=${encodeURIComponent(d.ical_url)}"
                        target="_blank" class="btn btn-outline-danger btn-sm">
                        <i class="bi bi-google me-1"></i>Google Calendar
                    </a>
                </div>
                <p class="small text-muted mt-3 mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    The feed updates every hour and includes all open tickets with due dates.
                    Calendar apps will receive reminders 1 day and 1 hour before each due date.
                </p>`);

            $('#btn-copy-ical').on('click', () => {
                const url = d.ical_url;
                navigator.clipboard?.writeText(url).then(() => {
                    $('#btn-copy-ical').html('<i class="bi bi-check"></i>');
                    setTimeout(() => $('#btn-copy-ical').html('<i class="bi bi-clipboard"></i>'), 2000);
                }).catch(() => {
                    $('#ical-url-input').select();
                });
            });
        } catch (e) {
            $('#subscribe-modal-body').html(`<div class="alert alert-danger mb-0">${App.escapeHtml(e.message || 'Failed to load subscription URL')}</div>`);
        }
    },

    isoDate(d) {
        return d.getFullYear() + '-' +
            String(d.getMonth() + 1).padStart(2, '0') + '-' +
            String(d.getDate()).padStart(2, '0');
    },
};
