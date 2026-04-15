const Notifications = {
    items: [],
    unreadCount: 0,
    activeCount: 0,
    lastKnownId: 0,
    pollTimer: null,
    fastRefreshTimer: null,
    initialised: false,
    browserSeenIds: new Set(),

    init() {
        this.stop();
        this.items = [];
        this.unreadCount = 0;
        this.activeCount = 0;
        this.lastKnownId = 0;
        this.browserSeenIds = new Set();
        this.initialised = true;

        if (!API.isAgent()) {
            return;
        }

        this.bindEvents();
        this.refreshSummary({ silent: true }).then(() => {
            if (API.isAdmin()) {
                this.checkForUpdatesSilently();
            }
        });

        this.pollTimer = window.setInterval(() => this.pollNew(), 10000);
        this.fastRefreshTimer = window.setInterval(() => this.refreshCountersOnly(), 5000);
        $(window)
            .off('.notificationsWindow')
            .on('focus.notificationsWindow visibilitychange.notificationsWindow', () => {
                if (!document.hidden) {
                    this.refreshSummary({ silent: true });
                }
            });
    },

    stop() {
        if (this.pollTimer) {
            window.clearInterval(this.pollTimer);
            this.pollTimer = null;
        }
        if (this.fastRefreshTimer) {
            window.clearInterval(this.fastRefreshTimer);
            this.fastRefreshTimer = null;
        }
        $('#navbar-container').off('.notifications');
        $(window).off('.notificationsWindow');
        this.initialised = false;
    },

    bindEvents() {
        $('#navbar-container')
            .off('.notifications')
            .on('show.bs.dropdown.notifications', '#notificationDropdown', () => {
                this.refreshSummary({ silent: true });
            })
            .on('click.notifications', '#notification-mark-all', async (e) => {
                e.preventDefault();
                try {
                    const res = await API.post('/notifications/read-all', {});
                    this.items = [];
                    this.unreadCount = res.data ? (res.data.unread_count || 0) : 0;
                    this.activeCount = res.data ? (res.data.active_count || 0) : this.activeCount;
                    this.render();
                    if (typeof Navbar !== 'undefined') {
                        Navbar.fetchOpenTicketCount();
                    }
                } catch (err) {
                    App.toast(err.message, 'error');
                }
            })
            .on('click.notifications', '.notification-item', async (e) => {
                e.preventDefault();
                const id = parseInt($(e.currentTarget).data('id'), 10);
                const link = $(e.currentTarget).data('link') || '';
                await this.markRead(id);
                if (link) {
                    App.navigate(link);
                }
            });
    },

    async refreshSummary({ silent = false } = {}) {
        if (!API.isAgent()) return;
        try {
            const res = await API.get('/notifications', { limit: 12 });
            this.items = (res.data && res.data.items) || [];
            this.unreadCount = (res.data && res.data.unread_count) || 0;
            this.activeCount = (res.data && res.data.active_count) || 0;
            this.lastKnownId = this.items.reduce((max, item) => Math.max(max, parseInt(item.id, 10) || 0), 0);
            this.items.forEach(item => this.browserSeenIds.add(parseInt(item.id, 10)));
            this.render();
        } catch (err) {
            if (!silent) {
                App.toast(err.message, 'error');
            }
        }
    },

    async refreshCountersOnly() {
        if (!API.isAgent() || !this.initialised) return;
        try {
            const res = await API.get('/notifications', { limit: 1 });
            this.unreadCount = (res.data && res.data.unread_count) || 0;
            this.activeCount = (res.data && res.data.active_count) || 0;
            this.renderBadge();
        } catch (err) {
            // Silent background refresh.
        }
    },

    async pollNew() {
        if (!API.isAgent() || !this.initialised) return;
        try {
            const res = await API.get('/notifications', {
                after_id: this.lastKnownId || 0,
                limit: 50,
            });
            const newItems = (res.data && res.data.items) || [];
            this.unreadCount = (res.data && res.data.unread_count) || 0;
            this.activeCount = (res.data && res.data.active_count) || 0;

            if (newItems.length) {
                newItems.forEach(item => {
                    const id = parseInt(item.id, 10) || 0;
                    this.browserSeenIds.add(id);
                    this.lastKnownId = Math.max(this.lastKnownId, id);
                });

                this.items = [...newItems.slice().reverse(), ...this.items]
                    .filter((item, index, all) => all.findIndex(candidate => String(candidate.id) === String(item.id)) === index)
                    .slice(0, 12);

                this.render();
                this.maybeShowBrowserNotifications(newItems);
            } else {
                this.renderBadge();
            }
        } catch (err) {
            // Silent background polling.
        }
    },

    async markRead(id) {
        if (!id) return;
        try {
            const res = await API.post('/notifications/' + id + '/read', {});
            this.items = this.items.filter(item => String(item.id) !== String(id));
            this.unreadCount = res.data ? (res.data.unread_count || 0) : Math.max(0, this.unreadCount - 1);
            this.activeCount = res.data ? (res.data.active_count || 0) : this.activeCount;
            this.render();
            if (typeof Navbar !== 'undefined') {
                Navbar.fetchOpenTicketCount();
            }
        } catch (err) {
            App.toast(err.message, 'error');
        }
    },

    render() {
        this.renderBadge();
        const $body = $('#notification-menu-body');
        if (!$body.length) return;

        if (!this.items.length) {
            const extra = this.activeCount > 0
                ? `<div class="terminal-notification-empty-link"><a href="#/my-profile/notifications">View ${App.escapeHtml(String(this.activeCount))} active notification${this.activeCount === 1 ? '' : 's'}</a></div>`
                : '';
            $body.html(`<div class="terminal-notification-empty">No unread notifications right now.</div>${extra}`);
            return;
        }

        $body.html(this.items.map(item => {
            const unreadClass = item.read_at ? '' : ' unread';
            const body = item.body ? `<div class="terminal-notification-body">${App.escapeHtml(item.body)}</div>` : '';
            return `
                <a href="#" class="dropdown-item terminal-notification-item notification-item${unreadClass}" data-id="${item.id}" data-link="${App.escapeHtml(item.link || '')}">
                    <div class="terminal-notification-title-row">
                        <span class="terminal-notification-title">${App.escapeHtml(item.title || 'Notification')}</span>
                        <span class="terminal-notification-time">${App.escapeHtml(App.formatDate(item.created_at))}</span>
                    </div>
                    ${body}
                </a>
            `;
        }).join(''));
    },

    renderBadge() {
        const $badge = $('#notification-badge');
        if (!$badge.length) return;
        $badge.removeClass('attention');
        if (this.unreadCount > 0) {
            $badge.text(this.unreadCount > 99 ? '99+' : String(this.unreadCount)).show();
        } else if (this.activeCount > 0) {
            $badge.text('!').addClass('attention').show();
        } else {
            $badge.hide();
        }
    },

    async markAllRead() {
        const res = await API.post('/notifications/read-all', {});
        this.items = [];
        this.unreadCount = res.data ? (res.data.unread_count || 0) : 0;
        this.activeCount = res.data ? (res.data.active_count || 0) : this.activeCount;
        this.render();
        return res.data || {};
    },

    async fetchActiveOverview(limit = 100) {
        const res = await API.get('/notifications/active', { limit });
        return {
            items: (res.data && res.data.items) || [],
            unreadCount: (res.data && res.data.unread_count) || 0,
            activeCount: (res.data && res.data.active_count) || 0,
        };
    },

    async checkForUpdatesSilently() {
        if (!API.isAdmin()) return;
        try {
            const res = await API.post('/notifications/check-updates', {});
            if (res.data && res.data.created) {
                await this.refreshSummary({ silent: true });
                const latest = (this.items || []).find(item => item.type === 'update_available' && !item.read_at);
                if (latest) {
                    this.maybeShowBrowserNotifications([latest]);
                }
            }
        } catch (err) {
            // Silent by design.
        }
    },

    browserNotificationsEnabled() {
        return API.isAgent()
            && !!API.currentUser
            && !!API.currentUser.browser_notifications_enabled
            && 'Notification' in window
            && Notification.permission === 'granted';
    },

    maybeShowBrowserNotifications(items) {
        if (!this.browserNotificationsEnabled()) return;
        if (!(document.hidden || !document.hasFocus())) return;

        items.forEach(item => {
            const id = parseInt(item.id, 10) || 0;
            if (!id || item.read_at) return;

            const note = new Notification(item.title || 'Andrea Helpdesk', {
                body: item.body || '',
                icon: App.settings.logo_url || '/Andrea-Helpdesk-favicon.png',
                tag: 'andrea-helpdesk-' + id,
            });

            note.onclick = () => {
                window.focus();
                if (item.link) {
                    App.navigate(item.link);
                }
                this.markRead(id);
                note.close();
            };
        });
    },

    async enableBrowserNotifications() {
        if (!('Notification' in window)) {
            throw new Error('This browser does not support notifications');
        }

        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
            throw new Error('Browser notification permission was not granted');
        }

        const res = await API.put('/agent/profile', { browser_notifications_enabled: true });
        if (API.currentUser) {
            API.currentUser.browser_notifications_enabled = true;
        }
        return res.data || {};
    },

    async disableBrowserNotifications() {
        const res = await API.put('/agent/profile', { browser_notifications_enabled: false });
        if (API.currentUser) {
            API.currentUser.browser_notifications_enabled = false;
        }
        return res.data || {};
    },

    sendTestBrowserNotification() {
        if (!this.browserNotificationsEnabled()) {
            throw new Error('Browser notifications are not enabled');
        }

        const note = new Notification('Andrea Helpdesk', {
            body: 'Browser notifications are enabled for this account.',
            icon: App.settings.logo_url || '/Andrea-Helpdesk-favicon.png',
            tag: 'andrea-helpdesk-test',
        });
        window.setTimeout(() => note.close(), 5000);
    },
};
