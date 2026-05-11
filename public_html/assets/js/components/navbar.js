/**
 * Andrea Helpdesk - Navigation Bar Component
 */
const Navbar = {
    openCount: 0,
    ticketPollTimer: null,

    renderNotificationDropdown(extraClass = '') {
        return `
            <div class="dropdown ${extraClass}" data-notification-dropdown>
                <button class="nav-link terminal-tool-toggle terminal-notification-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications">
                    <span class="terminal-notification-icon">
                        <i class="bi bi-bell"></i>
                        <span class="badge terminal-route-badge terminal-notification-badge" data-notification-badge style="display:none"></span>
                    </span>
                </button>
                <div class="dropdown-menu dropdown-menu-end terminal-nav-menu terminal-notification-menu">
                    <div class="terminal-notification-menu-header">
                        <div>
                            <div class="terminal-menu-heading">Notifications</div>
                            <strong>Inbox</strong>
                        </div>
                    </div>
                    <div class="terminal-notification-menu-body" data-notification-menu-body>
                        <div class="terminal-notification-empty">Loading…</div>
                    </div>
                    <div class="terminal-notification-menu-footer">
                        <a class="dropdown-item terminal-menu-link" href="#/my-profile/notifications" data-route="/my-profile/notifications">
                            <i class="bi bi-layout-text-window-reverse me-2"></i>Alerts Panel
                        </a>
                        <a class="dropdown-item terminal-menu-link" href="#/my-profile/settings/notifications" data-route="/my-profile/settings/notifications">
                            <i class="bi bi-sliders me-2"></i>Notification Settings
                        </a>
                    </div>
                </div>
            </div>
        `;
    },

    getPrimaryRoutes() {
        const routes = [];
        if (API.isAgent()) {
            routes.push(
                { label: 'Dashboard', icon: 'bi-speedometer2', route: '/' },
                { label: 'Tickets', icon: 'bi-ticket-perforated', route: '/tickets', badgeId: 'nav-ticket-badge' },
                { label: 'Calendar', icon: 'bi-calendar3', route: '/calendar' },
                { label: 'Customers', icon: 'bi-people', route: '/customers' },
                { label: 'Knowledge Base', icon: 'bi-book', route: '/kb' }
            );
            return routes;
        }

        return [
            { label: 'My Tickets', icon: 'bi-ticket', route: '/portal' }
        ];
    },

    renderRoute(route) {
        return `
            <a class="nav-link terminal-route-link" href="#${route.route}" data-route="${route.route}">
                <i class="bi ${route.icon}"></i>
                <span>${App.escapeHtml(route.label)}</span>
                ${route.badgeId ? `<span id="${route.badgeId}" class="badge terminal-route-badge" style="display:none">${this.openCount || ''}</span>` : ''}
            </a>
        `;
    },

    render() {
        const user    = API.currentUser;
        const isAdmin = API.isAdmin();
        const isAgent = API.isAgent();
        const currentTheme = isAgent ? ((API.currentUser && API.currentUser.theme) || 'light') : 'light';
        const themeBtnClass = (theme) => `terminal-theme-btn${currentTheme === theme ? ' active' : ''}`;
        const primaryRoutes = this.getPrimaryRoutes();
        const showAdmin = isAgent && (isAdmin || API.can('can_manage_tags') || API.can('can_view_reports'));
        const displayName = String((user && user.name) || user.email || 'User').trim();
        const firstName = displayName.split(/\s+/)[0] || 'User';

        if (!user) return '';

        return `
        <nav class="navbar navbar-expand-lg navbar-dark sticky-top terminal-nav">
            <div class="container-fluid">
                <a class="navbar-brand fw-bold terminal-brand" href="#/">
                    <img src="${App.escapeHtml(App.settings.logo_url || '/Andrea-Helpdesk.png')}" alt="${App.escapeHtml(App.appName)}" class="me-2">
                    <span class="terminal-brand-copy">
                        <strong>${App.escapeHtml(App.appName)}</strong>
                    </span>
                </a>
                ${isAgent ? `
                    <div class="terminal-nav-mobile-actions d-lg-none ms-auto">
                        ${this.renderNotificationDropdown('terminal-notification-mobile')}
                    </div>
                ` : ''}
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="mainNav">
                    <div class="terminal-nav-shell">
                        <div class="terminal-nav-routes">
                            ${primaryRoutes.map((route) => this.renderRoute(route)).join('')}
                        </div>
                        <div class="terminal-nav-tools">
                            ${isAgent ? `
                                ${this.renderNotificationDropdown('d-none d-lg-block')}
                            ` : ''}
                            ${showAdmin ? `
                                <div class="dropdown">
                                    <button class="nav-link dropdown-toggle terminal-tool-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="bi bi-sliders2"></i>
                                        <span>Admin</span>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end terminal-nav-menu">
                                        ${isAdmin ? `<li><a class="dropdown-item terminal-menu-link" href="#/admin/agents" data-route="/admin/agents"><i class="bi bi-people-fill me-2"></i>Agents</a></li>` : ''}
                                        ${isAdmin ? `<li><a class="dropdown-item terminal-menu-link" href="#/admin/settings/general" data-route="/admin/settings"><i class="bi bi-sliders me-2"></i>Settings</a></li>` : ''}
                                        ${API.can('can_view_reports') ? `<li><a class="dropdown-item terminal-menu-link" href="#/admin/reports" data-route="/admin/reports"><i class="bi bi-bar-chart me-2"></i>Reports</a></li>` : ''}
                                        ${(isAdmin || API.can('can_manage_tags')) ? `<li><a class="dropdown-item terminal-menu-link" href="#/admin/tags" data-route="/admin/tags"><i class="bi bi-tags me-2"></i>Tags</a></li>` : ''}
                                    </ul>
                                </div>
                            ` : ''}
                            <div class="dropdown">
                                <button class="nav-link dropdown-toggle terminal-tool-toggle terminal-user-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    <span class="terminal-user-toggle-copy">
                                        <small>${App.escapeHtml(currentTheme)} mode</small>
                                        <strong>${App.escapeHtml(firstName)}</strong>
                                    </span>
                                    <i class="bi bi-person-circle"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end terminal-nav-menu terminal-user-menu">
                                    <li class="terminal-user-card">
                                        <span class="terminal-menu-heading">User</span>
                                        <strong>${App.escapeHtml(user.name || 'User')}</strong>
                                        <small>${App.escapeHtml(user.email || '')}</small>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li class="px-2 pb-2">
                                        <span class="terminal-menu-heading d-block mb-2">Theme</span>
                                        <div class="terminal-theme-toggle">
                                            <button type="button" class="${themeBtnClass('light')}" data-theme-choice="light" aria-label="Switch to light theme">Light</button>
                                            <button type="button" class="${themeBtnClass('dark')}" data-theme-choice="dark" aria-label="Switch to dark theme">Dark</button>
                                        </div>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    ${isAgent ? `<li><a class="dropdown-item terminal-menu-link" href="#/my-profile" data-route="/my-profile"><i class="bi bi-person-lines-fill me-2"></i>My Profile</a></li>` : ''}
                                    <li><a class="dropdown-item terminal-menu-link" href="#" id="nav-logout"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </nav>`;
    },

    init() {
        $('#navbar-container').html(this.render());
        this.bindEvents();
        this.updateActiveItem();
        this.fetchOpenTicketCount();
        if (this.ticketPollTimer) {
            window.clearInterval(this.ticketPollTimer);
        }
        this.ticketPollTimer = window.setInterval(() => this.fetchOpenTicketCount(), 15000);
        if (typeof Notifications !== 'undefined') {
            Notifications.renderBadge();
        }
    },

    async setThemePreference(theme) {
        const next = theme === 'dark' ? 'dark' : 'light';
        App.applyTheme(next);
        if (API.currentUser) {
            API.currentUser.theme = next;
        }
        $('#navbar-container .terminal-theme-btn').removeClass('active');
        $('#navbar-container .terminal-theme-btn[data-theme-choice="' + next + '"]').addClass('active');

        if (!API.isAgent()) return;
        try {
            await API.put('/agent/profile', { theme: next });
        } catch (e) {
            // Non-blocking preview mode still updates local theme.
            App.toast('Theme updated locally only. Save from profile to persist.', 'warning');
        }
    },

    bindEvents() {
        $('#navbar-container').on('click', '#nav-logout', async (e) => {
            e.preventDefault();
            const refreshToken = localStorage.getItem('andrea_refresh_token');
            try {
                await API.post('/auth/logout', { refresh_token: refreshToken });
            } catch (e) {}
            API.clearTokens();
            window.location.hash = '#/login/agent';
            location.reload();
        });

        // Collapse mobile navbar on navigation
        $('#navbar-container').on('click', 'a[href^="#/"]', function() {
            const nav = document.getElementById('mainNav');
            const bsCollapse = bootstrap.Collapse.getInstance(nav);
            if (bsCollapse) bsCollapse.hide();
        });

        $('#navbar-container').on('click', '.terminal-theme-btn', (e) => {
            e.preventDefault();
            const theme = $(e.currentTarget).data('theme-choice');
            this.setThemePreference(theme);
        });
    },

    updateActiveItem() {
        const hash = window.location.hash.replace('#', '') || '/';
        $('#navbar-container [data-route]').each(function() {
            const route = $(this).data('route');
            if (route && (hash === route || (route !== '/' && hash.startsWith(route)))) {
                $(this).addClass('active');
            } else {
                $(this).removeClass('active');
            }
        });

        $('#navbar-container .dropdown').each(function() {
            const hasActiveChild = $(this).find('[data-route].active').length > 0;
            $(this).find('> .terminal-tool-toggle').toggleClass('active', hasActiveChild);
        });
    },

    setTicketBadge(count) {
        this.openCount = count;
        const badge = $('#nav-ticket-badge');
        if (count > 0) {
            badge.text(count).show();
        } else {
            badge.hide();
        }
    },

    async fetchOpenTicketCount() {
        if (!API.isAgent()) return;
        try {
            const res = await API.get('/tickets', { status: 'active', per_page: 1 });
            this.setTicketBadge(res.meta ? res.meta.total : 0);
        } catch (e) {}
    }
};
