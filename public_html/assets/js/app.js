/**
 * Andrea Helpdesk - Main SPA Router
 */
const App = {
    appName: 'Andrea Helpdesk',
    settings: {},

    routes: {
        '/':               'DashboardView',
        '/login':          'LoginView',
        '/tickets':        'TicketsView',
        '/tickets/new':    'TicketNewView',
        '/tickets/:id':    'TicketDetailView',
        '/customers':      'CustomersView',
        '/customers/:id':  'CustomerDetailView',
        '/admin/agents':   'AgentsView',
        '/admin/settings': 'SettingsView',
        '/admin/reports':  'ReportsView',
        '/kb':             'KnowledgeBaseView',
        '/kb/:slug':       'KbArticleView',
        '/portal/login':        'PortalLoginView',
        '/portal/set-password': 'PortalSetPasswordView',
        '/portal':              'PortalView',
        '/portal/tickets/:id':  'PortalTicketView',
    },

    publicRoutes: ['/login', '/portal/login'],

    async init() {
        // Hide loading screen
        $('#loading-screen').hide();
        $('#app').show();

        // Load public settings (no auth required) — sets title/brand for all screens
        await this.loadAppName();

        if (API.isAuthenticated()) {
            const user = await API.loadCurrentUser();
            if (user) {
                Navbar.init();
            } else {
                window.location.hash = '#/login';
                return;
            }
        }

        $(window).on('hashchange', () => this.route());
        this.route();
    },

    getHash() {
        return window.location.hash.replace(/^#/, '') || '/';
    },

    matchRoute(path) {
        const routes = Object.keys(this.routes);
        for (const pattern of routes) {
            const params = this.matchPattern(pattern, path);
            if (params !== null) {
                return { viewName: this.routes[pattern], params, pattern };
            }
        }
        return null;
    },

    matchPattern(pattern, path) {
        const [pathOnly, queryString] = path.split('?');
        const patternParts = pattern.split('/').filter(Boolean);
        const pathParts    = pathOnly.split('/').filter(Boolean);
        if (patternParts.length !== pathParts.length) return null;

        const params = {};
        for (let i = 0; i < patternParts.length; i++) {
            if (patternParts[i].startsWith(':')) {
                params[patternParts[i].slice(1)] = decodeURIComponent(pathParts[i]);
            } else if (patternParts[i] !== pathParts[i]) {
                return null;
            }
        }
        // Merge query string params
        if (queryString) {
            new URLSearchParams(queryString).forEach((v, k) => { params[k] = v; });
        }
        return params;
    },

    async route() {
        const hash    = this.getHash();
        const matched = this.matchRoute(hash);
        const viewName = matched ? matched.viewName : null;
        const params   = matched ? matched.params : {};

        // Auth check
        const isPublic = this.publicRoutes.some(p => hash.startsWith(p));
        if (!isPublic && !API.isAuthenticated()) {
            window.location.hash = '#/login';
            return;
        }

        // Admin route guard
        if (hash.startsWith('/admin/reports')) {
            if (!API.can('can_view_reports')) {
                this.toast('You do not have permission to view reports', 'error');
                window.location.hash = '#/';
                return;
            }
        } else if (hash.startsWith('/admin/settings')) {
            if (!API.isAdmin() && !API.can('can_manage_tags') && !API.can('can_manage_kb')) {
                this.toast('You do not have permission to access settings', 'error');
                window.location.hash = '#/';
                return;
            }
        } else if (hash.startsWith('/admin/') && !API.isAdmin()) {
            this.toast('Admin access required', 'error');
            window.location.hash = '#/';
            return;
        }

        // Get view from registry (const declarations don't attach to window)
        const viewRegistry = {
            DashboardView, LoginView, TicketsView, TicketNewView,
            TicketDetailView, CustomersView, CustomerDetailView,
            AgentsView, SettingsView, ReportsView,
            KnowledgeBaseView, KbArticleView,
            PortalLoginView, PortalSetPasswordView, PortalView, PortalTicketView,
        };
        const view = viewName && viewRegistry[viewName];
        if (!view) {
            $('#app').html('<div class="container mt-5"><div class="alert alert-warning">Page not found.</div></div>');
            return;
        }

        this.showLoading();
        Navbar.updateActiveItem();

        try {
            const html = (typeof view.render === 'function') ? view.render(params) : '<div></div>';
            $('#app').html(html);
            if (typeof view.init === 'function') await view.init(params);
        } catch (e) {
            $('#app').html('<div class="container mt-5"><div class="alert alert-danger">Error loading page: ' + (e.message || 'Unknown error') + '</div></div>');
            console.error(e);
        }

        this.hideLoading();
    },

    async loadAppName() {
        try {
            const res = await API.get('/settings/public');
            if (res.data) {
                this.settings = res.data;
                if (res.data.company_name) this.applyAppName(res.data.company_name);
                if (res.data.favicon_url)  this.applyFavicon(res.data.favicon_url);
            }
        } catch (e) {}
    },

    applyAppName(name) {
        this.appName = name;
        document.title = name;
        const hasLogo = !!this.settings.logo_url;
        const logo = hasLogo
            ? `<img src="${this.escapeHtml(this.settings.logo_url)}" alt="${this.escapeHtml(name)}" style="max-height:32px;max-width:120px;object-fit:contain;" class="me-2">`
            : `<i class="bi bi-headset me-2"></i>`;
        $('.navbar-brand').html(`${logo}${this.escapeHtml(name)}`).toggleClass('pe-3', hasLogo);
    },

    applyFavicon(url) {
        const link = document.getElementById('app-favicon');
        if (link && url) link.href = url;
    },

    navigate(path) {
        window.location.hash = '#' + path;
    },

    showLoading() {
        // Small top progress bar
    },

    hideLoading() {
        // Remove top progress bar
    },

    toast(message, type = 'success', duration = 3500) {
        const typeClass = {
            success: 'bg-success text-white',
            error:   'bg-danger text-white',
            warning: 'bg-warning text-dark',
            info:    'bg-info text-dark',
        }[type] || 'bg-secondary text-white';

        const id      = 'toast-' + Date.now();
        const html    = `
            <div id="${id}" class="toast align-items-center ${typeClass} border-0 mb-2" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body">${this.escapeHtml(message)}</div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            </div>`;
        $('#toast-container').append(html);
        const toastEl = document.getElementById(id);
        const toast   = new bootstrap.Toast(toastEl, { delay: duration });
        toast.show();
        toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
    },

    confirm(message, title = 'Confirm') {
        return new Promise(resolve => {
            $('#confirmModalTitle').text(title);
            $('#confirmModalBody').text(message);
            const modal = new bootstrap.Modal(document.getElementById('confirmModal'));
            modal.show();
            $('#confirmModalOk').off('click').on('click', () => {
                modal.hide();
                resolve(true);
            });
            $('#confirmModal').one('hidden.bs.modal', () => resolve(false));
        });
    },

    escapeHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    },

    // Format date for display using PHP-style date_format setting
    formatDate(dateStr) {
        if (!dateStr) return '–';
        const d = new Date(dateStr.replace(' ', 'T'));
        if (isNaN(d)) return dateStr;
        const fmt = this.settings.date_format || 'Y-m-d H:i';
        const pad = n => String(n).padStart(2, '0');
        const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
        const days   = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        return fmt.split('').map((c, i, arr) => {
            if (arr[i - 1] === '\\') return c;
            if (c === '\\') return '';
            switch (c) {
                case 'Y': return d.getFullYear();
                case 'y': return String(d.getFullYear()).slice(-2);
                case 'm': return pad(d.getMonth() + 1);
                case 'n': return d.getMonth() + 1;
                case 'd': return pad(d.getDate());
                case 'j': return d.getDate();
                case 'H': return pad(d.getHours());
                case 'G': return d.getHours();
                case 'i': return pad(d.getMinutes());
                case 's': return pad(d.getSeconds());
                case 'A': return d.getHours() < 12 ? 'AM' : 'PM';
                case 'a': return d.getHours() < 12 ? 'am' : 'pm';
                case 'g': return d.getHours() % 12 || 12;
                case 'h': return pad(d.getHours() % 12 || 12);
                case 'D': return days[d.getDay()].slice(0, 3);
                case 'l': return days[d.getDay()];
                case 'M': return months[d.getMonth()].slice(0, 3);
                case 'F': return months[d.getMonth()];
                case 't': return new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate();
                case 'N': return d.getDay() || 7;
                case 'w': return d.getDay();
                default:  return c;
            }
        }).join('');
    },

    statusBadge(status) {
        const map = {
            open:     'bg-primary',
            pending:  'bg-warning text-dark',
            resolved: 'bg-success',
            closed:   'bg-secondary',
        };
        return `<span class="badge ${map[status] || 'bg-light text-dark'}">${status}</span>`;
    },

    priorityBadge(priority) {
        const map = {
            urgent: 'bg-danger',
            high:   'bg-warning text-dark',
            normal: 'bg-info text-dark',
            low:    'bg-light text-dark border',
        };
        return `<span class="badge ${map[priority] || 'bg-light text-dark'}">${priority}</span>`;
    }
};

$(document).ready(() => App.init());
