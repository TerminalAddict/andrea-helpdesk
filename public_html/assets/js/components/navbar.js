/**
 * Andrea Helpdesk - Navigation Bar Component
 */
const Navbar = {
    openCount: 0,

    render() {
        const user    = API.currentUser;
        const isAdmin = API.isAdmin();
        const isAgent = API.isAgent();

        if (!user) return '';

        const agentNav = isAgent ? `
            <li class="nav-item">
                <a class="nav-link" href="#/" data-route="/">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#/tickets" data-route="/tickets">
                    <i class="bi bi-ticket-perforated"></i> Tickets
                    <span id="nav-ticket-badge" class="badge bg-danger ms-1" style="display:none">${this.openCount || ''}</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#/customers" data-route="/customers">
                    <i class="bi bi-people"></i> Customers
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#/kb" data-route="/kb">
                    <i class="bi bi-book"></i> Knowledge Base
                </a>
            </li>
            ${API.can('can_view_reports') ? `
            <li class="nav-item">
                <a class="nav-link" href="#/admin/reports" data-route="/admin/reports">
                    <i class="bi bi-bar-chart"></i> Reports
                </a>
            </li>` : ''}
            ${(isAdmin || API.can('can_manage_tags') || API.can('can_manage_kb')) ? `
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                    <i class="bi bi-gear"></i> Admin
                </a>
                <ul class="dropdown-menu dropdown-menu-dark">
                    ${isAdmin ? `<li><a class="dropdown-item" href="#/admin/agents"><i class="bi bi-people-fill me-2"></i>Agents</a></li>` : ''}
                    <li><a class="dropdown-item" href="#/admin/settings"><i class="bi bi-sliders me-2"></i>Settings</a></li>
                </ul>
            </li>` : ''}
        ` : `
            <li class="nav-item">
                <a class="nav-link" href="#/portal" data-route="/portal">
                    <i class="bi bi-ticket"></i> My Tickets
                </a>
            </li>
        `;

        return `
        <nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top">
            <div class="container-fluid">
                <a class="navbar-brand fw-bold pe-3" href="#/">
                    <img src="${App.escapeHtml(App.settings.logo_url || '/Andrea-Helpdesk.png')}" alt="${App.escapeHtml(App.appName)}" style="max-height:32px;max-width:120px;object-fit:contain;" class="me-2">${App.escapeHtml(App.appName)}
                </a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="mainNav">
                    <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                        ${agentNav}
                    </ul>
                    <ul class="navbar-nav">
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                                <i class="bi bi-person-circle me-1"></i>${App.escapeHtml(user.name || user.email)}
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark">
                                <li><span class="dropdown-item-text small" style="color:${API.isAdmin() ? '#a78bfa' : '#6ea8fe'};">${App.escapeHtml(user.email || '')}</span></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="#" id="nav-logout"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                            </ul>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>`;
    },

    init() {
        $('#navbar-container').html(this.render());
        this.bindEvents();
        this.updateActiveItem();
        this.fetchOpenTicketCount();
    },

    bindEvents() {
        $('#navbar-container').on('click', '#nav-logout', async (e) => {
            e.preventDefault();
            const refreshToken = localStorage.getItem('andrea_refresh_token');
            try {
                await API.post('/auth/logout', { refresh_token: refreshToken });
            } catch (e) {}
            API.clearTokens();
            window.location.hash = '#/login';
            location.reload();
        });

        // Collapse mobile navbar on navigation
        $('#navbar-container').on('click', 'a[href^="#/"]', function() {
            const nav = document.getElementById('mainNav');
            const bsCollapse = bootstrap.Collapse.getInstance(nav);
            if (bsCollapse) bsCollapse.hide();
        });
    },

    updateActiveItem() {
        const hash = window.location.hash.replace('#', '') || '/';
        $('#navbar-container .nav-link').each(function() {
            const route = $(this).data('route');
            if (route && (hash === route || (route !== '/' && hash.startsWith(route)))) {
                $(this).addClass('active');
            } else {
                $(this).removeClass('active');
            }
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
