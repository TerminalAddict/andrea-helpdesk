const Notifications = {
    items: [],
    activeCount: 0,
    lastKnownId: 0,
    pollTimer: null,
    fastRefreshTimer: null,
    initialised: false,
    browserSeenIds: new Set(),

    init() {
        this.stop();
        this.items = [];
        this.activeCount = 0;
        this.lastKnownId = 0;
        this.browserSeenIds = new Set();
        this.initialised = true;

        if (!API.isAgent()) {
            return;
        }

        this.bindEvents();
        this.bindServiceWorkerMessages();
        this.refreshSummary({ silent: true }).then(() => {
            if (API.isAdmin()) {
                this.checkForUpdatesSilently();
            }
        });
        this.refreshPushSubscriptionIfNeeded();

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
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.removeEventListener('message', this._serviceWorkerMessageHandler || (() => {}));
            this._serviceWorkerMessageHandler = null;
        }
        this.initialised = false;
    },

    bindEvents() {
        $('#navbar-container')
            .off('.notifications')
            .on('show.bs.dropdown.notifications', '[data-notification-dropdown]', () => {
                this.refreshSummary({ silent: true });
            })
            .on('click.notifications', '.notification-item', async (e) => {
                e.preventDefault();
                const link = $(e.currentTarget).data('link') || '';
                if (link) {
                    App.navigate(link);
                }
            });
    },

    bindServiceWorkerMessages() {
        if (!('serviceWorker' in navigator)) return;
        if (this._serviceWorkerMessageHandler) {
            navigator.serviceWorker.removeEventListener('message', this._serviceWorkerMessageHandler);
        }
        this._serviceWorkerMessageHandler = (event) => {
            const data = event.data || {};
            if (data.type === 'ANDREA_PUSH_SUBSCRIPTION_REFRESHED' && data.subscription) {
                const subscription = {
                    ...data.subscription,
                    contentEncoding: (PushManager.supportedContentEncodings && PushManager.supportedContentEncodings.includes('aes128gcm'))
                        ? 'aes128gcm'
                        : 'aesgcm',
                };
                API.post('/push/subscriptions', { subscription }).catch(() => {});
            }
            if (data.type === 'ANDREA_PUSH_SUBSCRIPTION_CHANGED') {
                this.refreshPushSubscriptionIfNeeded().catch(() => {});
            }
        };
        navigator.serviceWorker.addEventListener('message', this._serviceWorkerMessageHandler);
    },

    async refreshSummary({ silent = false } = {}) {
        if (!API.isAgent()) return;
        try {
            const res = await API.get('/notifications', { limit: 12 });
            this.items = (res.data && res.data.items) || [];
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

    render() {
        this.renderBadge();
        const $bodies = $('[data-notification-menu-body]');
        if (!$bodies.length) return;

        if (!this.items.length) {
            $bodies.html('<div class="terminal-notification-empty">No notifications right now.</div>');
            return;
        }

        const html = this.items.map(item => {
            const body = item.body ? `<div class="terminal-notification-body">${App.escapeHtml(item.body)}</div>` : '';
            return `
                <a href="#" class="dropdown-item terminal-notification-item notification-item" data-id="${item.id}" data-link="${App.escapeHtml(item.link || '')}">
                    <div class="terminal-notification-title-row">
                        <span class="terminal-notification-title">${App.escapeHtml(item.title || 'Notification')}</span>
                        <span class="terminal-notification-time">${App.escapeHtml(App.formatDate(item.created_at))}</span>
                    </div>
                    ${body}
                </a>
            `;
        }).join('');
        $bodies.html(html);
    },

    renderBadge() {
        const $badges = $('[data-notification-badge]');
        if (!$badges.length) return;
        if (this.activeCount > 0) {
            $badges.text(this.activeCount > 99 ? '99+' : String(this.activeCount)).show();
        } else {
            $badges.hide();
        }
    },

    async fetchActiveOverview(limit = 100) {
        const res = await API.get('/notifications/active', { limit });
        return {
            items: (res.data && res.data.items) || [],
            activeCount: (res.data && res.data.active_count) || 0,
        };
    },

    async checkForUpdatesSilently() {
        if (!API.isAdmin()) return;
        try {
            const res = await API.post('/notifications/check-updates', {});
            if (res.data && res.data.created) {
                await this.refreshSummary({ silent: true });
                const latest = (this.items || []).find(item => item.type === 'update_available');
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
            && Notification.permission === 'granted'
            && 'serviceWorker' in navigator
            && 'PushManager' in window;
    },

    maybeShowBrowserNotifications(items) {
        if (!this.browserNotificationsEnabled()) return;
        if (!(document.hidden || !document.hasFocus())) return;

        items.forEach(item => {
            const id = parseInt(item.id, 10) || 0;
            if (!id) return;

            const note = new Notification(item.title || App.appName || 'Andrea Helpdesk', {
                body: item.body || '',
                icon: App.pwaIconUrl(),
                tag: 'andrea-helpdesk-' + id,
            });

            note.onclick = () => {
                window.focus();
                if (item.link) {
                    App.navigate(item.link);
                }
                note.close();
            };
        });
    },

    async enableBrowserNotifications() {
        if (!('Notification' in window)) {
            throw new Error('This browser does not support notifications');
        }
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            throw new Error('This browser does not support Web Push notifications');
        }

        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
            throw new Error('Browser notification permission was not granted');
        }

        const configRes = await API.get('/push/config');
        const pushConfig = configRes.data || {};
        if (!pushConfig.configured || !pushConfig.public_key) {
            throw new Error('Push notifications are not configured by an administrator yet');
        }

        const registration = await this.registerServiceWorker();
        this.storeVapidPublicKey(registration, pushConfig.public_key);
        const existing = await registration.pushManager.getSubscription();
        if (existing) {
            await existing.unsubscribe().catch(() => {});
        }

        const subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: this.urlBase64ToUint8Array(pushConfig.public_key),
        });

        await API.post('/push/subscriptions', {
            subscription: this.serialisePushSubscription(subscription),
        });

        const res = await API.put('/agent/profile', { browser_notifications_enabled: true });
        if (API.currentUser) {
            API.currentUser.browser_notifications_enabled = true;
        }
        return res.data || {};
    },

    async refreshPushSubscriptionIfNeeded() {
        if (!API.isAgent()
            || !API.currentUser
            || !API.currentUser.browser_notifications_enabled
            || !('Notification' in window)
            || Notification.permission !== 'granted'
            || !('serviceWorker' in navigator)
            || !('PushManager' in window)) {
            return;
        }

        const configRes = await API.get('/push/config');
        const pushConfig = configRes.data || {};
        if (!pushConfig.configured || !pushConfig.public_key) {
            return;
        }

        const registration = await this.registerServiceWorker();
        this.storeVapidPublicKey(registration, pushConfig.public_key);
        let subscription = await registration.pushManager.getSubscription();
        if (!subscription) {
            subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: this.urlBase64ToUint8Array(pushConfig.public_key),
            });
        }

        await API.post('/push/subscriptions', {
            subscription: this.serialisePushSubscription(subscription),
        });
    },

    async disableBrowserNotifications() {
        if ('serviceWorker' in navigator) {
            try {
                const registration = await navigator.serviceWorker.getRegistration('/');
                const subscription = registration ? await registration.pushManager.getSubscription() : null;
                if (subscription) {
                    await API.delete('/push/subscriptions', { endpoint: subscription.endpoint }).catch(() => {});
                    await subscription.unsubscribe().catch(() => {});
                } else {
                    await API.delete('/push/subscriptions', {}).catch(() => {});
                }
            } catch (e) {
                await API.delete('/push/subscriptions', {}).catch(() => {});
            }
        } else {
            await API.delete('/push/subscriptions', {}).catch(() => {});
        }

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

        const note = new Notification(App.appName || 'Andrea Helpdesk', {
            body: 'Browser notifications are enabled for this account.',
            icon: App.pwaIconUrl(),
            tag: 'andrea-helpdesk-test',
        });
        window.setTimeout(() => note.close(), 5000);
    },

    async registerServiceWorker() {
        const registration = await navigator.serviceWorker.register('/service-worker.js', { scope: '/' });
        await navigator.serviceWorker.ready;
        return registration;
    },

    storeVapidPublicKey(registration, publicKey) {
        if (!registration || !registration.active || !publicKey) return;
        registration.active.postMessage({
            type: 'ANDREA_STORE_VAPID_PUBLIC_KEY',
            publicKey,
        });
    },

    serialisePushSubscription(subscription) {
        const json = subscription.toJSON();
        return {
            endpoint: json.endpoint,
            keys: json.keys || {},
            contentEncoding: (PushManager.supportedContentEncodings && PushManager.supportedContentEncodings.includes('aes128gcm'))
                ? 'aes128gcm'
                : 'aesgcm',
        };
    },

    urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);
        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    },
};
