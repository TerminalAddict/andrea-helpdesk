/**
 * Andrea Helpdesk - API Client
 * Wraps all API calls with JWT auth and automatic token refresh.
 */
const API = {
    baseUrl: (window.AppConfig && window.AppConfig.apiBase) || '/api',
    currentUser: null,
    _refreshing: false,

    getHeaders(isFormData) {
        const token = localStorage.getItem('andrea_access_token');
        const headers = {};
        if (!isFormData) headers['Content-Type'] = 'application/json';
        if (token) headers['Authorization'] = 'Bearer ' + token;
        return headers;
    },

    setTokens(accessToken, refreshToken) {
        localStorage.setItem('andrea_access_token', accessToken);
        if (refreshToken) localStorage.setItem('andrea_refresh_token', refreshToken);
    },

    clearTokens() {
        localStorage.removeItem('andrea_access_token');
        localStorage.removeItem('andrea_refresh_token');
        this.currentUser = null;
    },

    isAuthenticated() {
        return !!localStorage.getItem('andrea_access_token');
    },

    async refreshToken() {
        const refreshToken = localStorage.getItem('andrea_refresh_token');
        if (!refreshToken) return false;

        try {
            const res = await fetch(this.baseUrl + '/auth/refresh', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ refresh_token: refreshToken })
            });
            if (!res.ok) {
                this.clearTokens();
                return false;
            }
            const data = await res.json();
            if (data.success && data.data) {
                this.setTokens(data.data.access_token, data.data.refresh_token);
                return true;
            }
        } catch (e) {}
        this.clearTokens();
        return false;
    },

    async request(method, path, data = null, isFormData = false) {
        const url = this.baseUrl + path;
        const options = {
            method,
            headers: this.getHeaders(isFormData),
        };

        if (data !== null) {
            if (isFormData) {
                options.body = data; // FormData
            } else {
                options.body = JSON.stringify(data);
            }
        }

        let res = await fetch(url, options);

        // Auto-refresh on 401
        if (res.status === 401 && !this._refreshing) {
            this._refreshing = true;
            const refreshed = await this.refreshToken();
            this._refreshing = false;

            if (refreshed) {
                options.headers = this.getHeaders(isFormData);
                res = await fetch(url, options);
            } else {
                window.location.hash = '#/login';
                throw new Error('Session expired. Please log in again.');
            }
        }

        const json = await res.json().catch(() => ({ success: false, message: 'Invalid response' }));

        if (!json.success && res.status !== 200 && res.status !== 201) {
            const err = new Error(json.message || 'Request failed');
            err.errors = json.errors || {};
            err.status = res.status;
            throw err;
        }

        return json;
    },

    get(path, params = {}) {
        const qs = Object.keys(params).length
            ? '?' + new URLSearchParams(params).toString()
            : '';
        return this.request('GET', path + qs);
    },

    post(path, data) {
        return this.request('POST', path, data);
    },

    put(path, data) {
        return this.request('PUT', path, data);
    },

    delete(path, data = null) {
        return this.request('DELETE', path, data);
    },

    upload(path, formData) {
        return this.request('POST', path, formData, true);
    },

    async loadCurrentUser() {
        try {
            const res = await this.get('/auth/me');
            this.currentUser = res.data.user;
            this.currentUser.type = res.data.type;
            return this.currentUser;
        } catch (e) {
            this.clearTokens();
            return null;
        }
    },

    isAdmin() {
        return this.currentUser && this.currentUser.role === 'admin';
    },

    isAgent() {
        return this.currentUser && this.currentUser.type === 'agent';
    },

    can(permission) {
        if (!this.currentUser) return false;
        if (this.currentUser.role === 'admin') return true;
        return !!this.currentUser[permission];
    }
};
