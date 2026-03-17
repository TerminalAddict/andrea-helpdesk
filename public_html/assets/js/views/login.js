/**
 * Login View - Agent and Customer portal login
 */
const LoginView = {
    render() {
        return `
        <div class="min-vh-100 d-flex align-items-center justify-content-center bg-light">
            <div class="card shadow-sm" style="width:420px; max-width:100%;">
                <div class="card-body p-4">
                    <div class="text-center mb-4">
                        ${App.settings.logo_url
                            ? `<img src="${App.escapeHtml(App.settings.logo_url)}" alt="${App.escapeHtml(App.appName)}" style="max-height:80px;max-width:240px;object-fit:contain;">`
                            : `<i class="bi bi-headset text-primary" style="font-size:3rem;"></i>`}
                        <h4 class="mt-2 mb-0 fw-bold">${App.escapeHtml(App.appName)}</h4>
                    </div>

                    <ul class="nav nav-tabs mb-3" id="loginTabs">
                        <li class="nav-item">
                            <button class="nav-link active" data-tab="agent">Agent Login</button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" data-tab="customer">Customer Portal</button>
                        </li>
                    </ul>

                    <!-- Agent Login -->
                    <div id="tab-agent">
                        <div id="agent-error" class="alert alert-danger d-none"></div>
                        <form id="agent-login-form">
                            <div class="mb-3">
                                <label class="form-label">Email Address</label>
                                <input type="email" class="form-control" id="agent-email" required autocomplete="email">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Password</label>
                                <input type="password" class="form-control" id="agent-password" required autocomplete="current-password">
                            </div>
                            <button type="submit" class="btn btn-primary w-100" id="agent-login-btn">
                                <span class="spinner-border spinner-border-sm d-none me-2" id="agent-spinner"></span>
                                Sign In
                            </button>
                        </form>
                    </div>

                    <!-- Customer Portal -->
                    <div id="tab-customer" style="display:none;">
                        <div id="customer-error" class="alert alert-danger d-none"></div>
                        <div id="customer-success" class="alert alert-success d-none"></div>
                        <form id="customer-login-form">
                            <div class="mb-3">
                                <label class="form-label">Your Email Address</label>
                                <input type="email" class="form-control" id="customer-email" required>
                                <div class="form-text">We'll send you a login link.</div>
                            </div>
                            <button type="submit" class="btn btn-success w-100" id="customer-login-btn">
                                <span class="spinner-border spinner-border-sm d-none me-2" id="customer-spinner"></span>
                                Send Login Link
                            </button>
                        </form>
                        <hr>
                        <p class="text-center text-muted small">Already have a password?</p>
                        <form id="customer-password-form">
                            <div class="mb-2">
                                <input type="email" class="form-control mb-2" id="customer-email-pw" placeholder="Email">
                                <input type="password" class="form-control" id="customer-password" placeholder="Password">
                            </div>
                            <button type="submit" class="btn btn-outline-secondary w-100">Sign In with Password</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>`;
    },

    init() {
        // Tab switching
        $('[data-tab]').on('click', function() {
            const tab = $(this).data('tab');
            $('[data-tab]').removeClass('active');
            $(this).addClass('active');
            $('#tab-agent, #tab-customer').hide();
            $('#tab-' + tab).show();
        });

        // Agent login
        $('#agent-login-form').on('submit', async (e) => {
            e.preventDefault();
            const email    = $('#agent-email').val();
            const password = $('#agent-password').val();

            $('#agent-error').addClass('d-none');
            $('#agent-spinner').removeClass('d-none');
            $('#agent-login-btn').prop('disabled', true);

            try {
                const res = await API.post('/auth/login', { email, password, type: 'agent' });
                API.setTokens(res.data.access_token, res.data.refresh_token);
                API.currentUser = res.data.user;
                API.currentUser.type = 'agent';
                Navbar.init();
                App.navigate('/');
            } catch (err) {
                $('#agent-error').text(err.message || 'Login failed').removeClass('d-none');
            } finally {
                $('#agent-spinner').addClass('d-none');
                $('#agent-login-btn').prop('disabled', false);
            }
        });

        // Customer magic link
        $('#customer-login-form').on('submit', async (e) => {
            e.preventDefault();
            const email = $('#customer-email').val();

            $('#customer-error, #customer-success').addClass('d-none');
            $('#customer-spinner').removeClass('d-none');
            $('#customer-login-btn').prop('disabled', true);

            try {
                await API.post('/portal/auth/magic-link', { email });
                $('#customer-success').text('Check your inbox – a login link has been sent.').removeClass('d-none');
            } catch (err) {
                $('#customer-error').text(err.message || 'Failed to send link').removeClass('d-none');
            } finally {
                $('#customer-spinner').addClass('d-none');
                $('#customer-login-btn').prop('disabled', false);
            }
        });

        // Customer password login
        $('#customer-password-form').on('submit', async (e) => {
            e.preventDefault();
            const email    = $('#customer-email-pw').val();
            const password = $('#customer-password').val();

            $('#customer-error').addClass('d-none');

            try {
                const res = await API.post('/auth/login', { email, password, type: 'customer' });
                API.setTokens(res.data.access_token, res.data.refresh_token);
                API.currentUser = res.data.user;
                API.currentUser.type = 'customer';
                Navbar.init();
                App.navigate('/portal');
            } catch (err) {
                $('#customer-error').text(err.message || 'Login failed').removeClass('d-none');
            }
        });

        // Check for magic link token in URL
        const params = new URLSearchParams(window.location.hash.split('?')[1] || '');
        const token  = params.get('token');
        const email  = params.get('email');
        if (token && email) {
            this.verifyMagicLink(token, email);
        }
    },

    async verifyMagicLink(token, email) {
        try {
            const res = await API.post('/portal/auth/verify-magic-link', { token, email });
            API.setTokens(res.data.access_token, res.data.refresh_token);
            API.currentUser = res.data.user;
            API.currentUser.type = 'customer';
            Navbar.init();
            App.navigate('/portal');
        } catch (err) {
            $('#customer-error').text('Invalid or expired login link').removeClass('d-none');
            $('[data-tab="customer"]').click();
        }
    }
};
