/**
 * Andrea Helpdesk - Main SPA Router
 */
const App = {
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
        '/portal':         'PortalView',
        '/portal/tickets/:id': 'PortalTicketView',
    },

    publicRoutes: ['/login', '/portal/login'],

    async init() {
        // Hide loading screen
        $('#loading-screen').hide();
        $('#app').show();

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
        const patternParts = pattern.split('/').filter(Boolean);
        const pathParts    = path.split('?')[0].split('/').filter(Boolean);
        if (patternParts.length !== pathParts.length) return null;

        const params = {};
        for (let i = 0; i < patternParts.length; i++) {
            if (patternParts[i].startsWith(':')) {
                params[patternParts[i].slice(1)] = decodeURIComponent(pathParts[i]);
            } else if (patternParts[i] !== pathParts[i]) {
                return null;
            }
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
        if (hash.startsWith('/admin/') && !API.isAdmin()) {
            this.toast('Admin access required', 'error');
            window.location.hash = '#/';
            return;
        }

        // Reports permission
        if (hash.startsWith('/admin/reports') && !API.can('can_view_reports')) {
            this.toast('You do not have permission to view reports', 'error');
            window.location.hash = '#/';
            return;
        }

        // Get view
        const view = viewName && window[viewName];
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

    // Format date for display
    formatDate(dateStr) {
        if (!dateStr) return '–';
        const d = new Date(dateStr.replace(' ', 'T'));
        return d.toLocaleString();
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
