(function () {
    const seed = {
        user: {
            id: 1,
            name: 'Andrea Agent',
            email: 'agent@andreahelpdesk.com',
            role: 'admin',
            theme: 'light'
        },
        agents: [
            { id: 1, name: 'Andrea Agent', email: 'agent@andreahelpdesk.com', role: 'admin', is_active: 1, can_edit_customers: 1, can_view_reports: 1, can_manage_tags: 1, can_manage_kb: 1, can_close_tickets: 1, can_delete_tickets: 1 },
            { id: 2, name: 'Paul Willard', email: 'paul@example.test', role: 'admin', is_active: 1, can_edit_customers: 1, can_view_reports: 1, can_manage_tags: 1, can_manage_kb: 1, can_close_tickets: 1, can_delete_tickets: 1 },
            { id: 3, name: 'Jasmine Clarke', email: 'jasmine@example.test', role: 'agent', is_active: 1, can_edit_customers: 1, can_view_reports: 0, can_manage_tags: 0, can_manage_kb: 0, can_close_tickets: 1, can_delete_tickets: 0 }
        ],
        customers: [
            { id: 1, name: 'Harbour Freight', email: 'ops@harbourfreight.example', phone: '+64 9 555 0199', company: 'Harbour Freight', created_at: '2026-03-14 09:30:00', suppress_emails: 0, portal_status: 'Active', notes: 'Prefers concise operational updates.' },
            { id: 2, name: 'Loudas Projects', email: 'support@loudas.example', phone: '+64 9 555 0111', company: 'Loudas Projects', created_at: '2026-02-21 14:10:00', suppress_emails: 0, portal_status: 'Invited', notes: 'Main contact is Jasmine in dispatch.' },
            { id: 3, name: 'Acme Civil', email: 'hello@acmecivil.example', phone: '+64 9 555 0133', company: 'Acme Civil', created_at: '2026-01-09 11:00:00', suppress_emails: 0, portal_status: 'Active', notes: 'Uses the portal for almost all follow-up.' }
        ],
        tickets: [
            {
                id: 101,
                ticket_number: 'AHD-2026-04-20-101',
                subject: 'Invoice PDF missing branding footer in emailed copy',
                customer_id: 1,
                customer_name: 'Harbour Freight',
                customer_email: 'ops@harbourfreight.example',
                status: 'waiting_for_reply',
                priority: 'overdue',
                tag_names: 'billing,urgent',
                assigned_agent_id: 1,
                agent_name: 'Andrea Agent',
                reply_count: 4,
                created_at: '2026-04-19 16:16:00',
                updated_at: '2026-04-20 10:12:00',
                due_at: '2026-04-19 17:00:00',
                summary: 'Harbour Freight says emailed invoices are missing the branding footer, while browser previews still look correct.',
                thread: [
                    { type: 'customer', author: 'Harbour Freight', at: 'Apr 19, 4:16 pm', body: 'The PDF attached to the billing email is missing the branding footer on page two.' },
                    { type: 'agent', author: 'Andrea Agent', at: 'Apr 19, 4:28 pm', body: 'Thanks, I can reproduce that in the current build. I am checking the HTML-to-PDF export path now.' },
                    { type: 'internal', author: 'Paul Willard', at: 'Apr 19, 4:31 pm', body: 'Likely tied to the new branding footer partial. Check the email-safe stylesheet path and asset embedding.' },
                    { type: 'system', author: 'System', at: 'Apr 20, 10:12 am', body: 'Ticket marked overdue in demo mode. This message resets on refresh.' }
                ]
            },
            {
                id: 102,
                ticket_number: 'AHD-2026-04-20-102',
                subject: 'Calendar widget shows duplicate technician slot',
                customer_id: 2,
                customer_name: 'Loudas Projects',
                customer_email: 'support@loudas.example',
                status: 'pending',
                priority: 'normal',
                tag_names: 'calendar',
                assigned_agent_id: 3,
                agent_name: 'Jasmine Clarke',
                reply_count: 3,
                created_at: '2026-04-20 09:02:00',
                updated_at: '2026-04-20 09:58:00',
                due_at: '2026-04-22 13:00:00',
                summary: 'Loudas Projects sees the same technician listed twice for a booked calendar slot on smaller widths.',
                thread: [
                    { type: 'customer', author: 'Loudas Projects', at: 'Apr 20, 9:02 am', body: 'When we open the calendar, the booked slot duplicates the technician name and makes the day look double-booked.' },
                    { type: 'agent', author: 'Jasmine Clarke', at: 'Apr 20, 9:18 am', body: 'Could you send a screenshot of the duplicated row so I can compare the filtered state?' },
                    { type: 'customer', author: 'Loudas Projects', at: 'Apr 20, 9:58 am', body: 'Screenshot attached. It only happens when the week view opens on mobile width.' }
                ]
            },
            {
                id: 103,
                ticket_number: 'AHD-2026-04-20-103',
                subject: 'Portal login magic link loops back to login',
                customer_id: 3,
                customer_name: 'Acme Civil',
                customer_email: 'hello@acmecivil.example',
                status: 'replied',
                priority: 'high',
                tag_names: 'portal',
                assigned_agent_id: 1,
                agent_name: 'Andrea Agent',
                reply_count: 3,
                created_at: '2026-04-20 08:44:00',
                updated_at: '2026-04-20 10:07:00',
                due_at: '2026-04-23 11:30:00',
                summary: 'Acme Civil can receive the magic link, but clicking it sends them back to the portal login screen.',
                thread: [
                    { type: 'customer', author: 'Acme Civil', at: 'Apr 20, 8:44 am', body: 'The portal email link opens the login page again instead of signing me in.' },
                    { type: 'internal', author: 'Andrea Agent', at: 'Apr 20, 8:55 am', body: 'Likely route mismatch between /login/portal and /portal/login after the auth split.' },
                    { type: 'agent', author: 'Andrea Agent', at: 'Apr 20, 10:07 am', body: 'We are testing a fix in the demo environment. I will update you once the redirect target is corrected.' }
                ]
            }
        ],
        kb: [
            { id: 1, slug: 'customer-portal-login', title: 'How customers log into the portal', category_name: 'Customers', updated_at: '2026-04-18 09:20:00', is_published: 1, excerpt: 'Covers password setup, magic links, and the portal ticket view.', body: 'Customers can log in through the dedicated portal endpoint, use a magic link, or set a password after an invite. This demo article is read-only and resets on refresh.' },
            { id: 2, slug: 'imap-polling-health', title: 'Checking IMAP polling health', category_name: 'Email', updated_at: '2026-04-17 14:05:00', is_published: 1, excerpt: 'What to review when inbound email suddenly stops creating tickets.', body: 'Review the IMAP account status, the cron schedule, mailbox folders, and any authentication changes with the provider.' },
            { id: 3, slug: 'branding-assets', title: 'Branding assets and logo sizing', category_name: 'Branding', updated_at: '2026-04-16 16:10:00', is_published: 1, excerpt: 'Recommended logo sizes, favicon guidance, and email-safe dimensions.', body: 'Use a wide header logo for the top nav, a square icon for the favicon, and keep email logos under 600px wide.' }
        ],
        tags: ['billing', 'urgent', 'calendar', 'portal', 'imap', 'customer-update'],
        notifications: [
            { id: 1, title: 'Overdue: AHD-2026-04-20-101', body: 'Invoice PDF missing branding footer in emailed copy', route: '/tickets/101', read: false, active: true, created_at: '10:12 am' },
            { id: 2, title: 'Customer reply: AHD-2026-04-20-102', body: 'Loudas Projects replied with a new screenshot', route: '/tickets/102', read: false, active: true, created_at: '10:25 am' },
            { id: 3, title: 'Update available', body: 'Andrea Helpdesk 1.3.10 is ready to install', route: '/admin/settings/general', read: true, active: true, created_at: '8:05 am' }
        ],
        reports: {
            summary: { new: 4, waiting_for_reply: 3, pending: 5, replied: 7, overdue: 2, ticket_count: 14 },
            agents: [
                { agent_name: 'Andrea Agent', assigned: 6, created: 1, replies: 14, notes: 4, resolved: 3, closed: 2 },
                { agent_name: 'Paul Willard', assigned: 4, created: 1, replies: 8, notes: 5, resolved: 2, closed: 1 },
                { agent_name: 'Jasmine Clarke', assigned: 3, created: 0, replies: 6, notes: 2, resolved: 1, closed: 1 }
            ],
            volume: [
                { period: '2026-04-01', created: 2, customer_replies: 4, agent_replies: 5, internal_notes: 2, system_events: 1, total: 14 },
                { period: '2026-04-08', created: 3, customer_replies: 3, agent_replies: 7, internal_notes: 1, system_events: 2, total: 16 },
                { period: '2026-04-15', created: 4, customer_replies: 6, agent_replies: 8, internal_notes: 3, system_events: 2, total: 23 }
            ]
        },
        settings: {
            general: [
                ['Application Name', 'Andrea Helpdesk Demo'],
                ['Application URL', 'https://demo.andreahelpdesk.com'],
                ['Timezone', 'Pacific/Auckland'],
                ['Ticket Number Prefix', 'AHD'],
                ['IMAP Polling Mode', 'Disabled in demo'],
                ['Version', '1.3.10-demo']
            ],
            branding: [
                ['Logo URL', './assets/img/Andrea-Helpdesk.png'],
                ['Favicon URL', './assets/img/Andrea-Helpdesk-favicon.png'],
                ['Primary Colour', '#0d6efd'],
                ['Support Email (displayed)', 'support@andreahelpdesk.com']
            ],
            email: [
                ['SMTP Host', 'smtp.demo.invalid'],
                ['From Email', 'support@andreahelpdesk.com'],
                ['Global Signature', 'Demo mode only — no email is ever sent'],
                ['Include customer portal link', 'Enabled']
            ],
            autoresponse: [
                ['Enabled', 'Yes'],
                ['Subject', 'Re: {{subject}} [{{ticket_number}}]'],
                ['Preview', 'Thanks for contacting Andrea Helpdesk. This demo never sends email.']
            ],
            imap: [
                ['Enabled', 'No'],
                ['Host', 'imap.demo.invalid'],
                ['Status', 'Polling disabled in demo mode']
            ],
            slack: [
                ['Enabled', 'No'],
                ['Channel', '#helpdesk-demo'],
                ['Status', 'Slack disabled in demo mode']
            ]
        },
        calendar: [
            { date: '2026-04-21', title: 'IMAP polling review', owner: 'Andrea Agent', slot: '9:00am - 9:30am' },
            { date: '2026-04-21', title: 'Customer portal walkthrough', owner: 'Jasmine Clarke', slot: '1:00pm - 1:45pm' },
            { date: '2026-04-22', title: 'Branding approval window', owner: 'Paul Willard', slot: '10:30am - 11:15am' }
        ]
    };

    const Demo = {
        state: null,
        filters: {
            tickets: { q: '', status: 'active', priority: '', agent: '' },
            customers: { q: '' },
            kb: { q: '' },
            agents: { q: '' }
        },

        init() {
            document.body.classList.add('demo-mode');
            this.reset();
            this.bind();
            if (!location.hash) location.hash = '#/login/agent';
            this.route();
            setInterval(() => this.tickNotifications(), 45000);
        },

        reset() {
            this.state = JSON.parse(JSON.stringify(seed));
        },

        bind() {
            window.addEventListener('hashchange', () => this.route());
            $(window).on('scroll.scrolltop', () => {
                $('#scroll-to-top').toggleClass('visible', $(window).scrollTop() > 300);
            });
            $('#scroll-to-top').on('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));

            $(document).on('click', '[data-demo-action]', (e) => {
                e.preventDefault();
                this.action($(e.currentTarget).data('demo-action'), e.currentTarget);
            });

            $(document).on('submit', '#demo-login-form', (e) => {
                e.preventDefault();
                this.login();
            });

            $(document).on('input change', '[data-demo-filter]', (e) => {
                const el = e.currentTarget;
                const scope = el.dataset.scope;
                const key = el.dataset.demoFilter;
                this.filters[scope][key] = $(el).val();
                this.route();
            });

            $(document).on('submit', '.demo-ephemeral-form', (e) => {
                e.preventDefault();
                this.toast('Demo mode: action looked successful, but nothing was saved.', 'warning');
            });
        },

        isAuthed() {
            return sessionStorage.getItem('andrea-demo-auth') === '1';
        },

        hash() {
            return location.hash.replace(/^#/, '') || '/';
        },

        route() {
            let path = this.hash();
            if (path === '/login') path = '/login/agent';
            if (!this.isAuthed() && !path.startsWith('/login')) {
                location.hash = '#/login/agent';
                return;
            }
            if (this.isAuthed() && path.startsWith('/login')) {
                location.hash = '#/';
                return;
            }

            if (this.isAuthed()) {
                $('#navbar-container').html(this.renderNav(path));
            } else {
                $('#navbar-container').empty();
            }

            $('#app').html(path.startsWith('/login') ? this.renderLogin(path) : this.renderView(path));
            this.bindNotificationMenu();
        },

        renderNav(path) {
            const unread = this.state.notifications.filter((n) => !n.read).length;
            const active = this.state.notifications.filter((n) => n.active).length;
            const count = unread > 0 ? unread : active;
            const badgeClass = unread > 0 ? '' : ' attention';
            const firstName = this.state.user.name.split(/\s+/)[0] || 'User';
            const primary = [
                ['/', 'Dashboard', 'bi-speedometer2'],
                ['/tickets', 'Tickets', 'bi-ticket-perforated'],
                ['/calendar', 'Calendar', 'bi-calendar3'],
                ['/customers', 'Customers', 'bi-people'],
                ['/kb', 'Knowledge Base', 'bi-book']
            ];
            return `
                <nav class="navbar navbar-expand-lg navbar-dark sticky-top terminal-nav">
                    <div class="container-fluid">
                        <a class="navbar-brand fw-bold terminal-brand" href="#/">
                            <img src="./assets/img/Andrea-Helpdesk.png" alt="Andrea Helpdesk" class="me-2">
                            <span class="terminal-brand-copy"><strong>${this.escape(DemoConfig.appName)}</strong></span>
                        </a>
                        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
                            <span class="navbar-toggler-icon"></span>
                        </button>
                        <div class="collapse navbar-collapse" id="mainNav">
                            <div class="terminal-nav-shell">
                                <div class="terminal-nav-routes">
                                    ${primary.map(([route, label, icon]) => `
                                        <a class="nav-link terminal-route-link ${this.isActive(path, route) ? 'active' : ''}" href="#${route}">
                                            <i class="bi ${icon}"></i>
                                            <span>${label}</span>
                                            ${route === '/tickets' ? `<span class="badge terminal-route-badge" style="${count ? '' : 'display:none'}">${this.openTicketCount()}</span>` : ''}
                                        </a>
                                    `).join('')}
                                </div>
                                <div class="terminal-nav-tools">
                                    <div class="dropdown">
                                        <button class="nav-link terminal-tool-toggle terminal-notification-toggle" type="button" data-bs-toggle="dropdown">
                                            <span class="terminal-notification-icon">
                                                <i class="bi bi-bell"></i>
                                                <span class="badge terminal-route-badge terminal-notification-badge${count ? badgeClass : ''}" style="${count ? '' : 'display:none'}">${count || ''}</span>
                                            </span>
                                        </button>
                                        <div class="dropdown-menu dropdown-menu-end terminal-nav-menu terminal-notification-menu demo-notification-menu">
                                            <div class="terminal-notification-menu-header">
                                                <div>
                                                    <div class="terminal-menu-heading">Notifications</div>
                                                    <strong>Inbox</strong>
                                                </div>
                                                <a href="#" class="small text-decoration-none" data-demo-action="mark-all-read">Mark all read</a>
                                            </div>
                                            <div id="demo-notification-menu-body" class="terminal-notification-menu-body"></div>
                                            <div class="terminal-notification-menu-footer">
                                                <a class="dropdown-item terminal-menu-link" href="#/my-profile/notifications">
                                                    <i class="bi bi-layout-text-window-reverse me-2"></i>Alerts Panel
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="dropdown">
                                        <button class="nav-link dropdown-toggle terminal-tool-toggle ${path.startsWith('/admin/') ? 'active' : ''}" type="button" data-bs-toggle="dropdown">
                                            <i class="bi bi-sliders2"></i>
                                            <span>Admin</span>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end terminal-nav-menu">
                                            <li><a class="dropdown-item terminal-menu-link" href="#/admin/agents"><i class="bi bi-people-fill me-2"></i>Agents</a></li>
                                            <li><a class="dropdown-item terminal-menu-link" href="#/admin/settings/general"><i class="bi bi-sliders me-2"></i>Settings</a></li>
                                            <li><a class="dropdown-item terminal-menu-link" href="#/admin/reports"><i class="bi bi-bar-chart me-2"></i>Reports</a></li>
                                            <li><a class="dropdown-item terminal-menu-link" href="#/admin/tags"><i class="bi bi-tags me-2"></i>Tags</a></li>
                                        </ul>
                                    </div>
                                    <div class="dropdown">
                                        <button class="nav-link dropdown-toggle terminal-tool-toggle terminal-user-toggle ${path.startsWith('/my-profile') ? 'active' : ''}" type="button" data-bs-toggle="dropdown">
                                            <span class="terminal-user-toggle-copy">
                                                <small>light mode</small>
                                                <strong>${this.escape(firstName)}</strong>
                                            </span>
                                            <i class="bi bi-person-circle"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end terminal-nav-menu terminal-user-menu">
                                            <li class="terminal-user-card">
                                                <span class="terminal-menu-heading">User</span>
                                                <strong>${this.escape(this.state.user.name)}</strong>
                                                <small>${this.escape(this.state.user.email)}</small>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li class="px-2 pb-2">
                                                <span class="terminal-menu-heading d-block mb-2">Theme</span>
                                                <div class="terminal-theme-toggle">
                                                    <button type="button" class="terminal-theme-btn active">Light</button>
                                                    <button type="button" class="terminal-theme-btn" disabled>Dark</button>
                                                </div>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><a class="dropdown-item terminal-menu-link" href="#/my-profile"><i class="bi bi-person-lines-fill me-2"></i>My Profile</a></li>
                                            <li><a class="dropdown-item terminal-menu-link" href="#" data-demo-action="logout"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </nav>
            `;
        },

        renderLogin(path) {
            const portal = path === '/login/portal';
            return `
                <div class="terminal-login-shell min-vh-100 d-flex align-items-center justify-content-center">
                    <div class="card terminal-login-card shadow-sm">
                        <div class="card-body p-4">
                            <div class="text-center mb-4">
                                <img src="./assets/img/Andrea-Helpdesk.png" alt="${this.escape(DemoConfig.appName)}" style="max-height:80px;max-width:240px;object-fit:contain;">
                                <h4 class="mt-2 mb-0 fw-bold">${this.escape(DemoConfig.appName)}</h4>
                            </div>
                            <ul class="nav nav-tabs mb-3">
                                <li class="nav-item"><a class="nav-link ${portal ? '' : 'active'}" href="#/login/agent">Agent Login</a></li>
                                <li class="nav-item"><a class="nav-link ${portal ? 'active' : ''}" href="#/login/portal">Customer Portal</a></li>
                            </ul>
                            <div class="alert alert-info py-2 small demo-no-save">
                                Demo mode: use <strong>agent@andreahelpdesk.com</strong> / <strong>agentpassword</strong>. Nothing is saved and no email is sent.
                            </div>
                            ${portal ? `
                                <div class="alert alert-success">Customer portal is present visually in the real app, but this demo focuses on the agent/admin experience.</div>
                                <a href="#/login/agent" class="btn btn-primary w-100">Back to Agent Demo</a>
                            ` : `
                                <div id="demo-login-error" class="alert alert-danger d-none"></div>
                                <form id="demo-login-form">
                                    <div class="mb-3">
                                        <label class="form-label">Email Address</label>
                                        <input type="email" class="form-control" id="demo-email" value="agent@andreahelpdesk.com" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Password</label>
                                        <input type="password" class="form-control" id="demo-password" value="agentpassword" required>
                                    </div>
                                    <div class="mb-3 form-check">
                                        <input type="checkbox" class="form-check-input" id="demo-remember" checked>
                                        <label class="form-check-label text-muted" for="demo-remember">Remember me</label>
                                    </div>
                                    <button type="submit" class="btn btn-primary w-100">Sign In</button>
                                </form>
                            `}
                        </div>
                    </div>
                </div>
            `;
        },

        renderView(path) {
            const ticket = path.match(/^\/tickets\/(\d+)$/);
            const customer = path.match(/^\/customers\/(\d+)$/);
            const kb = path.match(/^\/kb\/([^/]+)$/);
            const settings = path.match(/^\/admin\/settings\/([^/]+)$/);
            const profile = path.match(/^\/my-profile\/([^/]+)$/);

            if (path === '/') return this.dashboardView();
            if (path === '/tickets') return this.ticketsView();
            if (path === '/tickets/new') return this.ticketNewView();
            if (ticket) return this.ticketDetailView(Number(ticket[1]));
            if (path === '/calendar') return this.calendarView();
            if (path === '/customers') return this.customersView();
            if (customer) return this.customerDetailView(Number(customer[1]));
            if (path === '/kb') return this.kbListView();
            if (kb) return this.kbDetailView(kb[1]);
            if (path === '/admin/agents') return this.agentsView();
            if (path === '/admin/tags') return this.tagsView();
            if (path === '/admin/reports') return this.reportsView();
            if (path === '/my-profile') return this.profileView('profile');
            if (profile) return this.profileView(profile[1]);
            if (settings) return this.settingsView(settings[1]);
            if (path === '/admin/settings' || path === '/admin/settings/general') return this.settingsView('general');
            return `<div class="container-fluid terminal-screen p-4"><div class="alert alert-warning">Page not found.</div></div>`;
        },

        dashboardView() {
            const metrics = [
                ['new', 'New', 'text-info'],
                ['waiting_for_reply', 'Waiting for Reply', 'text-danger'],
                ['pending', 'Pending', 'text-warning'],
                ['replied', 'Replied', 'text-success'],
                ['overdue', 'Overdue', 'text-danger']
            ];
            const recent = [...this.state.tickets].sort((a, b) => b.updated_at.localeCompare(a.updated_at));
            const overdue = this.state.tickets.filter((t) => t.priority === 'overdue');
            const mine = this.state.tickets.filter((t) => t.assigned_agent_id === this.state.user.id);
            return `
                <div class="container-fluid terminal-screen terminal-screen-dashboard p-4">
                    <h4 class="terminal-heading mb-4"><i class="bi bi-speedometer2 me-2"></i>Dashboard</h4>
                    <div class="alert alert-warning demo-banner demo-no-save mb-4">
                        <strong>Demo mode.</strong> The dashboard, counts, and notifications feel live, but every action resets on refresh and no external systems are touched.
                    </div>
                    <div class="row g-3 mb-4 terminal-stat-row">
                        ${metrics.map(([key, label, color]) => `
                            <div class="col-6 col-md-4 col-xl demo-mini-stat">
                                <div class="card border-0 shadow-sm h-100">
                                    <div class="card-body text-center py-3">
                                        <div class="fs-1 fw-bold ${color}">${this.state.reports.summary[key] || 0}</div>
                                        <div class="text-muted small">${label} Tickets</div>
                                    </div>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                    <div class="mb-3 terminal-toolbar">
                        <div class="d-flex gap-2">
                            <div class="input-group input-group-lg shadow-sm flex-grow-1">
                                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                                <input type="search" class="form-control border-start-0 ps-0" value="${this.escape(this.filters.tickets.q)}" data-scope="tickets" data-demo-filter="q" placeholder="Search tickets…" autocomplete="off">
                            </div>
                            <a href="#/tickets/new" class="btn btn-primary btn-lg shadow-sm text-nowrap"><i class="bi bi-plus-lg me-1"></i>New Ticket</a>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-lg-6">${this.ticketTableCard('Overdue Tickets', 'bi-exclamation-octagon text-danger', overdue)}</div>
                        <div class="col-lg-6">${this.ticketTableCard('My Assigned Tickets', 'bi-person-check', mine)}</div>
                    </div>
                    <div class="row g-3 mt-1">
                        <div class="col-12">${this.ticketTableCard('Recently Updated', 'bi-clock-history', recent)}</div>
                    </div>
                </div>
            `;
        },

        ticketsView() {
            const f = this.filters.tickets;
            const tickets = this.state.tickets.filter((t) => {
                const statusOk = !f.status || f.status === 'active'
                    ? !['resolved', 'closed'].includes(t.status)
                    : t.status === f.status;
                const priorityOk = !f.priority || t.priority === f.priority;
                const agentOk = !f.agent || String(t.assigned_agent_id) === String(f.agent);
                const qOk = !f.q || `${t.ticket_number} ${t.subject} ${t.customer_name}`.toLowerCase().includes(f.q.toLowerCase());
                return statusOk && priorityOk && agentOk && qOk;
            });
            return `
                <div class="container-fluid terminal-screen terminal-screen-tickets p-4">
                    <div class="terminal-screen-header d-flex justify-content-between align-items-center mb-3">
                        <h4 class="mb-0"><i class="bi bi-ticket-perforated me-2"></i>Tickets</h4>
                        <a href="#/tickets/new" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>New Ticket</a>
                    </div>
                    <div class="card border-0 shadow-sm terminal-control-card mb-3">
                        <div class="card-body py-2">
                            <div class="row g-2 align-items-end">
                                <div class="col-md-2">
                                    <select class="form-select form-select-sm" data-scope="tickets" data-demo-filter="status">
                                        ${this.options(['active','new','waiting_for_reply','replied','pending'], f.status, { active: 'Active (all open)' })}
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <select class="form-select form-select-sm" data-scope="tickets" data-demo-filter="priority">
                                        ${this.options(['','overdue','high','normal'], f.priority, { '': 'All Priorities' })}
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <select class="form-select form-select-sm" data-scope="tickets" data-demo-filter="agent">
                                        <option value="">All Agents</option>
                                        ${this.state.agents.map((a) => `<option value="${a.id}" ${String(f.agent) === String(a.id) ? 'selected' : ''}>${this.escape(a.name)}</option>`).join('')}
                                    </select>
                                </div>
                                <div class="col-md-5">
                                    <input type="search" class="form-control form-control-sm" data-scope="tickets" data-demo-filter="q" value="${this.escape(f.q)}" placeholder="Search tickets...">
                                </div>
                                <div class="col-md-1">
                                    <button class="btn btn-secondary btn-sm w-100" data-demo-action="clear-ticket-filters">Clear</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card border-0 shadow-sm terminal-table-card">
                        <div class="card-body p-0">${this.ticketTable(tickets)}</div>
                    </div>
                </div>
            `;
        },

        ticketNewView() {
            return `
                <div class="container-fluid terminal-screen p-4">
                    <div class="terminal-screen-header d-flex justify-content-between align-items-center mb-3">
                        <h4 class="mb-0"><i class="bi bi-plus-circle me-2"></i>New Ticket</h4>
                        <a href="#/tickets" class="btn btn-outline-secondary btn-sm">Back to Tickets</a>
                    </div>
                    <div class="row g-3 demo-form-shell">
                        <div class="col-lg-8">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <form class="demo-ephemeral-form">
                                        <div class="mb-3">
                                            <label class="form-label">Customer</label>
                                            <input class="form-control" value="Harbour Freight" readonly>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Subject</label>
                                            <input class="form-control" placeholder="Short ticket summary">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Message</label>
                                            <textarea class="form-control" rows="10" placeholder="Describe the problem"></textarea>
                                        </div>
                                        <button class="btn btn-primary">Create Ticket</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white fw-semibold py-2">Demo Behaviour</div>
                                <div class="card-body">
                                    <p class="text-muted small mb-0">This screen looks real, but new tickets are not created. It only demonstrates the layout and the customer picker flow.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        },

        ticketDetailView(id) {
            const t = this.state.tickets.find((row) => row.id === id);
            if (!t) return `<div class="container-fluid terminal-screen p-4"><div class="alert alert-warning">Ticket not found.</div></div>`;
            const customer = this.state.customers.find((c) => c.id === t.customer_id);
            return `
                <div class="container-fluid terminal-screen p-4">
                    <nav aria-label="breadcrumb" class="mb-3">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="#/tickets">Tickets</a></li>
                            <li class="breadcrumb-item active">${this.escape(t.ticket_number)}</li>
                        </ol>
                    </nav>
                    <div class="row g-3">
                        <div class="col-lg-8">
                            <div class="card border-0 shadow-sm mb-3">
                                <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <div>
                                        <div class="small text-muted font-monospace">${this.escape(t.ticket_number)}</div>
                                        <h4 class="mb-0">${this.escape(t.subject)}</h4>
                                    </div>
                                    <div class="d-flex gap-2 flex-wrap">
                                        ${this.statusBadge(t.status)}
                                        ${this.priorityBadge(t.priority)}
                                        <button class="btn btn-outline-secondary btn-sm" data-demo-action="fake-save">Edit Ticket</button>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <p>${this.escape(t.summary)}</p>
                                </div>
                            </div>
                            <div class="demo-ticket-thread">
                                ${t.thread.map((item) => `
                                    <div class="card border-0 shadow-sm mb-3 ${item.type === 'internal' ? 'demo-thread-note' : item.type === 'system' ? 'demo-thread-system' : ''}">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between gap-3 mb-2">
                                                <strong>${this.escape(item.author)}</strong>
                                                <span class="small text-muted">${this.escape(item.at)}</span>
                                            </div>
                                            <div>${this.escape(item.body)}</div>
                                        </div>
                                    </div>
                                `).join('')}
                            </div>
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white fw-semibold py-2">Reply or Internal Note</div>
                                <div class="card-body">
                                    <form class="demo-ephemeral-form">
                                        <textarea class="form-control mb-3" rows="6" placeholder="Nothing entered here is saved."></textarea>
                                        <div class="d-flex gap-2">
                                            <button class="btn btn-primary">Send Reply</button>
                                            <button class="btn btn-outline-secondary">Add Internal Note</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="card border-0 shadow-sm mb-3">
                                <div class="card-header bg-white fw-semibold py-2"><i class="bi bi-person me-2"></i>Customer</div>
                                <div class="card-body">
                                    <div class="fw-semibold">${this.escape(customer.name)}</div>
                                    <div>${this.escape(customer.email)}</div>
                                    <div class="text-muted small">${this.escape(customer.phone)}</div>
                                </div>
                            </div>
                            <div class="card border-0 shadow-sm mb-3">
                                <div class="card-header bg-white fw-semibold py-2"><i class="bi bi-info-circle me-2"></i>Ticket Meta</div>
                                <div class="card-body small">
                                    <div class="mb-2"><strong>Assigned:</strong> ${this.escape(t.agent_name)}</div>
                                    <div class="mb-2"><strong>Due:</strong> ${this.escape(this.formatDateTime(t.due_at))}</div>
                                    <div><strong>Tags:</strong> ${t.tag_names.split(',').map((tag) => `<span class="badge bg-secondary me-1">${this.escape(tag)}</span>`).join('')}</div>
                                </div>
                            </div>
                            <div class="alert alert-warning demo-no-save mb-0">The edit and reply actions are intentionally non-persistent.</div>
                        </div>
                    </div>
                </div>
            `;
        },

        calendarView() {
            return `
                <div class="container-fluid terminal-screen terminal-screen-calendar py-3">
                    <div class="terminal-screen-header d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                        <h4 class="terminal-heading mb-0"><i class="bi bi-calendar3 me-2"></i>Calendar</h4>
                        <button class="btn btn-outline-success btn-sm" data-demo-action="fake-save"><i class="bi bi-calendar-plus me-1"></i>Subscribe</button>
                    </div>
                    <div class="card border-0 shadow-sm terminal-calendar-card">
                        <div class="card-header bg-white d-flex align-items-center justify-content-between py-2">
                            <div class="d-flex gap-2 align-items-center">
                                <button class="btn btn-sm btn-outline-secondary" data-demo-action="fake-save"><i class="bi bi-chevron-left"></i></button>
                                <button class="btn btn-sm btn-outline-secondary" data-demo-action="fake-save"><i class="bi bi-chevron-right"></i></button>
                                <button class="btn btn-sm btn-outline-primary" data-demo-action="fake-save">Today</button>
                            </div>
                            <h5 class="mb-0 fw-semibold">April 2026</h5>
                            <div style="width:160px;"></div>
                        </div>
                        <div class="card-body">
                            <div class="row g-3 demo-calendar-list">
                                ${this.state.calendar.map((event) => `
                                    <div class="col-md-4">
                                        <div class="card border-0 shadow-sm h-100">
                                            <div class="card-body">
                                                <div class="small text-muted">${event.date}</div>
                                                <div class="fw-semibold">${this.escape(event.title)}</div>
                                                <div class="small text-muted">${this.escape(event.owner)}</div>
                                                <div class="mt-2">${this.escape(event.slot)}</div>
                                            </div>
                                        </div>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    </div>
                </div>
            `;
        },

        customersView() {
            const q = this.filters.customers.q.toLowerCase();
            const rows = this.state.customers.filter((c) => !q || `${c.name} ${c.email} ${c.company}`.toLowerCase().includes(q));
            return `
                <div class="container-fluid terminal-screen terminal-screen-customers p-4">
                    <div class="terminal-screen-header d-flex justify-content-between align-items-center mb-3">
                        <h4 class="terminal-heading mb-0"><i class="bi bi-people me-2"></i>Customers</h4>
                        <div class="d-flex gap-2">
                            <button class="btn btn-outline-secondary btn-sm" data-demo-action="fake-save"><i class="bi bi-upload me-1"></i>Import CSV</button>
                            <button class="btn btn-primary btn-sm" data-demo-action="fake-save"><i class="bi bi-person-plus me-1"></i>New Customer</button>
                        </div>
                    </div>
                    <div class="card border-0 shadow-sm mb-3 terminal-control-card">
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-5">
                                    <input type="search" class="form-control form-control-sm" data-scope="customers" data-demo-filter="q" value="${this.escape(this.filters.customers.q)}" placeholder="Search by name or email…">
                                </div>
                                <div class="col-md-1">
                                    <button class="btn btn-secondary btn-sm w-100" data-demo-action="clear-customer-filter">Clear</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card border-0 shadow-sm terminal-table-card">
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr><th>Name</th><th>Company</th><th>Portal</th><th>Open Tickets</th></tr>
                                    </thead>
                                    <tbody>
                                        ${rows.map((c) => `
                                            <tr style="cursor:pointer;" onclick="location.hash='#/customers/${c.id}'">
                                                <td><div class="fw-semibold">${this.escape(c.name)}</div><div class="small text-muted">${this.escape(c.email)}</div></td>
                                                <td>${this.escape(c.company)}</td>
                                                <td>${this.escape(c.portal_status)}</td>
                                                <td>${this.state.tickets.filter((t) => t.customer_id === c.id).length}</td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        },

        customerDetailView(id) {
            const c = this.state.customers.find((row) => row.id === id);
            if (!c) return `<div class="container-fluid terminal-screen p-4"><div class="alert alert-warning">Customer not found.</div></div>`;
            const tickets = this.state.tickets.filter((t) => t.customer_id === id);
            return `
                <div class="container-fluid terminal-screen terminal-screen-customer p-4">
                    <nav aria-label="breadcrumb" class="mb-3">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="#/customers">Customers</a></li>
                            <li class="breadcrumb-item active">${this.escape(c.name)}</li>
                        </ol>
                    </nav>
                    <div class="row g-3">
                        <div class="col-lg-4">
                            <div class="card border-0 shadow-sm mb-3">
                                <div class="card-header bg-white fw-semibold py-2 d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-person me-2"></i>Customer</span>
                                    <button class="btn btn-sm btn-outline-secondary py-0" data-demo-action="fake-save">Edit</button>
                                </div>
                                <div class="card-body">
                                    <div class="mb-2"><div class="text-muted small">Name</div><div class="fw-semibold">${this.escape(c.name)}</div></div>
                                    <div class="mb-2"><div class="text-muted small">Email</div><div>${this.escape(c.email)}</div></div>
                                    <div class="mb-2"><div class="text-muted small">Phone</div><div>${this.escape(c.phone)}</div></div>
                                    <div class="mb-2"><div class="text-muted small">Company</div><div>${this.escape(c.company)}</div></div>
                                    <div class="mb-2"><div class="text-muted small">Customer since</div><div class="small">${this.formatDateTime(c.created_at)}</div></div>
                                </div>
                            </div>
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white fw-semibold py-2"><i class="bi bi-shield-lock me-2"></i>Portal Access</div>
                                <div class="card-body">
                                    <p class="small text-muted mb-2">Send a portal invitation link to this customer.</p>
                                    <button class="btn btn-sm btn-outline-primary mb-3" data-demo-action="fake-save"><i class="bi bi-envelope me-1"></i>Send Portal Invite</button>
                                    <hr class="my-2">
                                    <p class="small text-muted mb-2">Set a new portal password for this customer.</p>
                                    <input type="password" class="form-control form-control-sm mb-2" placeholder="New password">
                                    <input type="password" class="form-control form-control-sm mb-2" placeholder="Confirm password">
                                    <button class="btn btn-sm btn-outline-secondary" data-demo-action="fake-save"><i class="bi bi-key me-1"></i>Set Password</button>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-8">
                            <div class="card border-0 shadow-sm mb-3">
                                <div class="card-header bg-white fw-semibold py-2 d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-ticket-perforated me-2"></i>Tickets</span>
                                    <div class="d-flex gap-2">
                                        <a href="#/tickets" class="btn btn-sm btn-outline-secondary"><i class="bi bi-list me-1"></i>All tickets</a>
                                        <a href="#/tickets/new" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>New Ticket</a>
                                    </div>
                                </div>
                                <div class="card-body p-0">${this.ticketTable(tickets, true)}</div>
                            </div>
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white fw-semibold py-2"><i class="bi bi-chat-left-text me-2"></i>Comments by this Customer</div>
                                <div class="card-body">
                                    ${tickets.flatMap((t) => t.thread.filter((item) => item.type === 'customer').map((item) => `
                                        <div class="border rounded p-3 mb-2">
                                            <div class="small text-muted mb-1">${this.escape(t.ticket_number)} · ${this.escape(item.at)}</div>
                                            <div>${this.escape(item.body)}</div>
                                        </div>
                                    `)).join('') || '<p class="text-muted mb-0">No comments found.</p>'}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        },

        kbListView() {
            const q = this.filters.kb.q.toLowerCase();
            const rows = this.state.kb.filter((a) => !q || `${a.title} ${a.excerpt} ${a.category_name}`.toLowerCase().includes(q));
            return `
                <div class="container-fluid terminal-screen terminal-screen-knowledge-base p-4">
                    <div class="terminal-screen-header d-flex justify-content-between align-items-center mb-4">
                        <h4 class="terminal-heading mb-0"><i class="bi bi-book me-2"></i>Knowledge Base</h4>
                        <div class="d-flex gap-2">
                            <button class="btn btn-outline-secondary btn-sm" data-demo-action="fake-save"><i class="bi bi-tags me-1"></i>Categories</button>
                            <button class="btn btn-primary btn-sm" data-demo-action="fake-save"><i class="bi bi-plus-lg me-1"></i>New Article</button>
                        </div>
                    </div>
                    <div class="card border-0 shadow-sm mb-3 terminal-control-card">
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-5">
                                    <input type="search" class="form-control form-control-sm" data-scope="kb" data-demo-filter="q" value="${this.escape(this.filters.kb.q)}" placeholder="Search articles…">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="demo-kb-list">
                        ${rows.map((a) => `
                            <div class="card border-0 shadow-sm mb-2 terminal-kb-article-card" style="cursor:pointer;" onclick="location.hash='#/kb/${a.slug}'">
                                <div class="card-body py-3">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="mb-1 fw-semibold">${this.escape(a.title)}</h6>
                                            <div class="small text-muted">
                                                <span class="badge terminal-kb-category me-1">${this.escape(a.category_name)}</span>
                                                Updated ${this.formatDateTime(a.updated_at)}
                                            </div>
                                        </div>
                                        <div class="d-flex gap-1 ms-2">
                                            <button class="btn btn-sm btn-outline-secondary" data-demo-action="fake-save"><i class="bi bi-pencil"></i></button>
                                            <button class="btn btn-sm btn-outline-danger" data-demo-action="fake-save"><i class="bi bi-trash"></i></button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>
            `;
        },

        kbDetailView(slug) {
            const article = this.state.kb.find((a) => a.slug === slug);
            if (!article) return `<div class="container-fluid terminal-screen p-4"><div class="alert alert-warning">Article not found.</div></div>`;
            return `
                <div class="container-fluid terminal-screen p-4">
                    <nav aria-label="breadcrumb" class="mb-3">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="#/kb">Knowledge Base</a></li>
                            <li class="breadcrumb-item active">${this.escape(article.title)}</li>
                        </ol>
                    </nav>
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center">
                            <div>
                                <div class="small text-muted">${this.escape(article.category_name)}</div>
                                <h4 class="mb-0">${this.escape(article.title)}</h4>
                            </div>
                            <button class="btn btn-outline-secondary btn-sm" data-demo-action="fake-save"><i class="bi bi-pencil me-1"></i>Edit</button>
                        </div>
                        <div class="card-body">
                            <p>${this.escape(article.body)}</p>
                        </div>
                    </div>
                </div>
            `;
        },

        agentsView() {
            const q = this.filters.agents.q.toLowerCase();
            const rows = this.state.agents.filter((a) => !q || `${a.name} ${a.email} ${a.role}`.toLowerCase().includes(q));
            return `
                <div class="container-fluid terminal-screen terminal-screen-agents p-4">
                    <div class="terminal-screen-header d-flex justify-content-between align-items-center mb-4">
                        <h4 class="terminal-heading mb-0"><i class="bi bi-people-fill me-2"></i>Agents</h4>
                        <button class="btn btn-primary btn-sm" data-demo-action="fake-save"><i class="bi bi-plus-lg me-1"></i>Add Agent</button>
                    </div>
                    <div class="card border-0 shadow-sm mb-3 terminal-control-card">
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-5"><input type="search" class="form-control form-control-sm" data-scope="agents" data-demo-filter="q" value="${this.escape(this.filters.agents.q)}" placeholder="Search agents…"></div>
                            </div>
                        </div>
                    </div>
                    <div id="agents-table-wrap" class="card border-0 shadow-sm terminal-control-card">
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr><th>Agent</th><th>Role</th><th>Permissions</th><th>Status</th><th style="width:160px;"></th></tr>
                                    </thead>
                                    <tbody>
                                        ${rows.map((a) => `
                                            <tr>
                                                <td><div class="fw-semibold">${this.escape(a.name)}</div><div class="small text-muted">${this.escape(a.email)}</div></td>
                                                <td><span class="badge ${a.role === 'admin' ? 'bg-danger' : 'bg-primary'}">${a.role}</span></td>
                                                <td class="small">
                                                    ${['can_close_tickets','can_delete_tickets','can_edit_customers','can_view_reports','can_manage_kb','can_manage_tags']
                                                        .filter((p) => a[p])
                                                        .map((p) => `<span class="badge bg-light text-dark border me-1 small">${p.replace('can_','')}</span>`).join('')}
                                                </td>
                                                <td><span class="badge ${a.is_active ? 'bg-success' : 'bg-secondary'}">${a.is_active ? 'Active' : 'Inactive'}</span></td>
                                                <td>
                                                    <button class="btn btn-sm btn-outline-secondary me-1" data-demo-action="fake-save">Edit</button>
                                                    <button class="btn btn-sm btn-outline-warning" data-demo-action="fake-save">${a.is_active ? 'Deactivate' : 'Activate'}</button>
                                                </td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        },

        settingsView(section) {
            const tabs = ['general', 'branding', 'email', 'autoresponse', 'imap', 'slack'];
            const active = tabs.includes(section) ? section : 'general';
            return `
                <div class="container-fluid terminal-screen terminal-screen-settings p-4 terminal-compact">
                    <h4 class="terminal-heading mb-3"><i class="bi bi-sliders me-2"></i>Settings</h4>
                    <ul class="nav nav-tabs d-none d-md-flex demo-settings-nav">
                        ${tabs.map((tab) => `<li class="nav-item"><a class="nav-link ${tab === active ? 'active' : ''}" href="#/admin/settings/${tab}">${this.title(tab)}</a></li>`).join('')}
                    </ul>
                    <div class="card border-0 shadow-sm mt-3">
                        <div class="card-body demo-settings-grid">
                            <div class="row g-3">
                                ${this.state.settings[active].map(([label, value]) => `
                                    <div class="col-md-6">
                                        <div class="card border-0 shadow-sm h-100">
                                            <div class="card-body">
                                                <div class="text-muted small">${this.escape(label)}</div>
                                                <div class="fw-semibold mt-1">${this.escape(value)}</div>
                                            </div>
                                        </div>
                                    </div>
                                `).join('')}
                            </div>
                            ${active === 'general' ? `
                                <div class="card border-0 shadow-sm mt-3" id="version-card">
                                    <div class="card-header bg-white fw-semibold py-2"><i class="bi bi-box-seam me-2"></i>Version &amp; Updates</div>
                                    <div class="card-body py-3">
                                        <div class="d-flex align-items-center gap-3 flex-wrap">
                                            <div>
                                                <div class="small text-muted">Installed version</div>
                                                <div class="fw-semibold">${this.escape(DemoConfig.version)}</div>
                                            </div>
                                            <button class="btn btn-outline-secondary btn-sm" data-demo-action="fake-save"><i class="bi bi-arrow-repeat me-1"></i>Check for Updates</button>
                                        </div>
                                        <div class="alert alert-secondary py-2 mt-3 mb-0">
                                            <div class="fw-semibold small mb-1"><i class="bi bi-info-circle me-1"></i>Demo note</div>
                                            <div class="small text-muted mb-0">Updater controls are shown for realism only. The demo never downloads or installs anything.</div>
                                        </div>
                                    </div>
                                </div>
                            ` : ''}
                        </div>
                    </div>
                </div>
            `;
        },

        tagsView() {
            return `
                <div class="container-fluid terminal-screen p-4">
                    <div class="terminal-screen-header d-flex justify-content-between align-items-center mb-3">
                        <h4 class="mb-0"><i class="bi bi-tags me-2"></i>Tags</h4>
                        <button class="btn btn-primary btn-sm" data-demo-action="fake-save"><i class="bi bi-plus-lg me-1"></i>New Tag</button>
                    </div>
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            ${this.state.tags.map((tag) => `<span class="badge bg-secondary me-2 mb-2">${this.escape(tag)}</span>`).join('')}
                        </div>
                    </div>
                </div>
            `;
        },

        reportsView() {
            const s = this.state.reports.summary;
            return `
                <div class="container-fluid terminal-screen terminal-screen-reports p-4">
                    <h4 class="terminal-heading mb-4"><i class="bi bi-bar-chart me-2"></i>Reports</h4>
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body py-2">
                            <div class="row g-2 align-items-end">
                                <div class="col-md-2"><label class="form-label small mb-1">From</label><input type="date" class="form-control form-control-sm" value="2026-04-01"></div>
                                <div class="col-md-2"><label class="form-label small mb-1">To</label><input type="date" class="form-control form-control-sm" value="2026-04-20"></div>
                                <div class="col-md-2"><button class="btn btn-primary btn-sm" data-demo-action="fake-save"><i class="bi bi-play me-1"></i>Run Report</button></div>
                            </div>
                        </div>
                    </div>
                    <div class="row g-3 mb-4">
                        ${[
                            ['New', s.new, 'text-info'],
                            ['Waiting for Reply', s.waiting_for_reply, 'text-danger'],
                            ['Pending', s.pending, 'text-warning'],
                            ['Replied', s.replied, 'text-success'],
                            ['Overdue', s.overdue, 'text-danger'],
                            ['Tickets In Scope', s.ticket_count, 'text-body']
                        ].map(([label, value, color]) => `
                            <div class="col-6 col-md-3">
                                <div class="card border-0 shadow-sm h-100">
                                    <div class="card-body text-center py-3">
                                        <div class="fs-2 fw-bold ${color}">${value}</div>
                                        <div class="text-muted small">${label}</div>
                                    </div>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white fw-semibold py-2"><i class="bi bi-graph-up me-2"></i>Ticket Activity (Daily)</div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-sm mb-0">
                                            <thead class="table-light"><tr><th>Date</th><th>Created</th><th>Customer Replies</th><th>Agent Replies</th><th>Notes</th><th>System</th><th>Total</th></tr></thead>
                                            <tbody>${this.state.reports.volume.map((v) => `<tr><td>${v.period}</td><td>${v.created}</td><td>${v.customer_replies}</td><td>${v.agent_replies}</td><td>${v.internal_notes}</td><td>${v.system_events}</td><td>${v.total}</td></tr>`).join('')}</tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white fw-semibold py-2"><i class="bi bi-person-check me-2"></i>Agent Activity</div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-sm mb-0">
                                            <thead class="table-light"><tr><th>Agent</th><th>Assigned</th><th>Created</th><th>Replies</th><th>Notes</th><th>Resolved</th><th>Closed</th></tr></thead>
                                            <tbody>${this.state.reports.agents.map((a) => `<tr><td>${this.escape(a.agent_name)}</td><td>${a.assigned}</td><td>${a.created}</td><td>${a.replies}</td><td>${a.notes}</td><td>${a.resolved}</td><td>${a.closed}</td></tr>`).join('')}</tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        },

        profileView(section) {
            if (section === 'notifications') {
                return `
                    <div class="container-fluid terminal-screen p-4">
                        <div class="terminal-screen-header d-flex justify-content-between align-items-center mb-3">
                            <h4 class="mb-0"><i class="bi bi-bell me-2"></i>Notifications</h4>
                            <button class="btn btn-outline-secondary btn-sm" data-demo-action="fake-save">Subscribe In Your Browser</button>
                        </div>
                        <div class="card border-0 shadow-sm">
                            <div class="list-group list-group-flush demo-alert-list">
                                ${this.state.notifications.filter((n) => n.active).map((n) => `
                                    <a class="list-group-item list-group-item-action" href="#${n.route}">
                                        <div class="d-flex w-100 justify-content-between gap-3">
                                            <h6 class="mb-1">${this.escape(n.title)}</h6>
                                            <small class="text-muted">${this.escape(n.created_at)}</small>
                                        </div>
                                        <p class="mb-1 small text-muted">${this.escape(n.body)}</p>
                                        <small>${n.read ? 'Read' : 'Unread'}</small>
                                    </a>
                                `).join('')}
                            </div>
                        </div>
                    </div>
                `;
            }
            return `
                <div class="container-fluid terminal-screen p-4">
                    <div class="terminal-screen-header d-flex justify-content-between align-items-center mb-3">
                        <h4 class="mb-0"><i class="bi bi-person-lines-fill me-2"></i>My Profile</h4>
                        <button class="btn btn-primary btn-sm" data-demo-action="fake-save">Save Profile</button>
                    </div>
                    <div class="row g-3 demo-profile-grid">
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-body">
                                    <div class="text-muted small">Name</div>
                                    <div class="fw-semibold mt-1">${this.escape(this.state.user.name)}</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-body">
                                    <div class="text-muted small">Email</div>
                                    <div class="fw-semibold mt-1">${this.escape(this.state.user.email)}</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-body">
                                    <div class="text-muted small">Theme</div>
                                    <div class="fw-semibold mt-1">Light mode only in the demo</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-body">
                                    <div class="text-muted small">Notifications</div>
                                    <div class="fw-semibold mt-1"><a href="#/my-profile/notifications">Open Alerts Panel</a></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        },

        ticketTableCard(title, iconClass, tickets) {
            return `
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-semibold">
                        <i class="bi ${iconClass} me-2"></i>${title}
                    </div>
                    <div class="card-body p-0">${this.ticketTable(tickets, true)}</div>
                </div>
            `;
        },

        ticketTable(tickets, compact = false) {
            if (!tickets.length) return '<p class="text-muted p-4 text-center mb-0">No tickets found.</p>';
            return `
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Ticket #</th>
                                <th>Subject / Customer</th>
                                <th>Status</th>
                                <th>Priority</th>
                                ${compact ? '<th>Updated</th>' : '<th>Assigned To</th><th>Updated</th>'}
                            </tr>
                        </thead>
                        <tbody>
                            ${tickets.map((t) => `
                                <tr style="cursor:pointer;" onclick="location.hash='#/tickets/${t.id}'">
                                    <td><span class="font-monospace small fw-semibold">${this.escape(t.ticket_number)}</span></td>
                                    <td><div>${this.escape(t.subject)}</div><div class="small text-muted">${this.escape(t.customer_name || '')}</div></td>
                                    <td>${this.statusBadge(t.status)}</td>
                                    <td>${this.priorityBadge(t.priority)}</td>
                                    ${compact ? `<td class="small text-muted text-nowrap">${this.formatDateTime(t.updated_at)}</td>` : `<td class="small">${this.escape(t.agent_name || '')}</td><td class="small text-muted text-nowrap">${this.formatDateTime(t.updated_at)}</td>`}
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            `;
        },

        bindNotificationMenu() {
            const unread = this.state.notifications.filter((n) => !n.read && n.active);
            const active = this.state.notifications.filter((n) => n.active);
            $('#demo-notification-menu-body').html(
                unread.length ? unread.map((n) => `
                    <div class="demo-notification-item px-3 py-3 border-bottom">
                        <div class="d-flex justify-content-between gap-3">
                            <strong>${this.escape(n.title)}</strong>
                            <span class="small text-muted">${this.escape(n.created_at)}</span>
                        </div>
                        <div class="small text-muted mt-1">${this.escape(n.body)}</div>
                        <div class="mt-2 d-flex gap-2">
                            <a class="btn btn-sm btn-outline-secondary" href="#${n.route}">Open</a>
                            <button class="btn btn-sm btn-outline-danger" data-demo-action="mark-read" data-id="${n.id}">Mark read</button>
                        </div>
                    </div>
                `).join('') : `<div class="terminal-notification-empty p-3">${active.length ? 'No unread alerts. Active issues still appear in the Alerts Panel.' : 'No notifications right now.'}</div>`
            );
        },

        action(action, element) {
            if (action === 'logout') {
                sessionStorage.removeItem('andrea-demo-auth');
                location.hash = '#/login/agent';
                return;
            }
            if (action === 'mark-read') {
                const id = Number(element.dataset.id);
                const note = this.state.notifications.find((n) => n.id === id);
                if (note) note.read = true;
                this.route();
                return;
            }
            if (action === 'mark-all-read') {
                this.state.notifications.forEach((n) => { n.read = true; });
                this.route();
                return;
            }
            if (action === 'clear-ticket-filters') {
                this.filters.tickets = { q: '', status: 'active', priority: '', agent: '' };
                this.route();
                return;
            }
            if (action === 'clear-customer-filter') {
                this.filters.customers.q = '';
                this.route();
                return;
            }
            this.toast('Demo mode: action looked successful, but nothing was saved.', 'warning');
        },

        login() {
            const email = ($('#demo-email').val() || '').trim().toLowerCase();
            const password = ($('#demo-password').val() || '');
            if (email === 'agent@andreahelpdesk.com' && password === 'agentpassword') {
                sessionStorage.setItem('andrea-demo-auth', '1');
                location.hash = '#/';
                this.toast('Demo session started.', 'success');
                return;
            }
            $('#demo-login-error').text('Use agent@andreahelpdesk.com / agentpassword').removeClass('d-none');
        },

        tickNotifications() {
            if (!this.isAuthed()) return;
            const templates = [
                { title: 'New ticket: AHD-2026-04-20-104', body: 'A fresh demo ticket arrived from Harbour Freight.', route: '/tickets' },
                { title: 'Assignment update', body: 'Andrea Agent was assigned a simulated escalation.', route: '/admin/agents' },
                { title: 'Reminder: overdue issue', body: 'The branding PDF bug is still marked overdue in the demo queue.', route: '/tickets/101' }
            ];
            const pick = templates[Math.floor(Math.random() * templates.length)];
            const id = Math.max.apply(null, this.state.notifications.map((n) => n.id)) + 1;
            this.state.notifications.unshift({ id, title: pick.title, body: pick.body, route: pick.route, read: false, active: true, created_at: 'just now' });
            this.route();
        },

        openTicketCount() {
            return this.state.tickets.filter((t) => !['resolved', 'closed'].includes(t.status)).length;
        },

        isActive(path, route) {
            return path === route || (route !== '/' && path.startsWith(route + '/'));
        },

        statusBadge(status) {
            const labels = {
                new: ['bg-info text-dark', 'New'],
                waiting_for_reply: ['bg-danger', 'Waiting for reply'],
                replied: ['bg-success', 'Replied'],
                pending: ['bg-warning text-dark', 'Pending'],
                overdue: ['bg-danger', 'Overdue']
            };
            const [klass, label] = labels[status] || ['bg-secondary', this.title(status)];
            return `<span class="badge ${klass}">${this.escape(label)}</span>`;
        },

        priorityBadge(priority) {
            const labels = {
                overdue: ['bg-danger', 'Overdue'],
                urgent: ['bg-danger', 'Urgent'],
                high: ['bg-warning text-dark', 'High'],
                normal: ['bg-primary', 'Normal'],
                low: ['bg-secondary', 'Low']
            };
            const [klass, label] = labels[priority] || ['bg-secondary', this.title(priority)];
            return `<span class="badge ${klass}">${this.escape(label)}</span>`;
        },

        formatDateTime(value) {
            if (!value) return '–';
            const d = new Date(value.replace(' ', 'T'));
            return d.toLocaleString([], { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
        },

        options(values, selected, labels) {
            return values.map((value) => {
                const label = Object.prototype.hasOwnProperty.call(labels, value) ? labels[value] : this.title(value);
                return `<option value="${value}" ${value === selected ? 'selected' : ''}>${this.escape(label)}</option>`;
            }).join('');
        },

        title(value) {
            return String(value || '').replace(/_/g, ' ').replace(/\b\w/g, (s) => s.toUpperCase());
        },

        escape(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        },

        toast(message, type) {
            const classes = {
                success: 'bg-success text-white',
                warning: 'bg-warning text-dark',
                error: 'bg-danger text-white',
                info: 'bg-info text-dark'
            };
            const id = 'toast-' + Date.now();
            $('#toast-container').append(`
                <div id="${id}" class="toast align-items-center border-0 ${classes[type] || 'bg-secondary text-white'}" role="alert">
                    <div class="d-flex">
                        <div class="toast-body">${this.escape(message)}</div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                    </div>
                </div>
            `);
            const toast = new bootstrap.Toast(document.getElementById(id), { delay: 2400 });
            document.getElementById(id).addEventListener('hidden.bs.toast', function () { this.remove(); });
            toast.show();
        }
    };

    Demo.init();
})();
