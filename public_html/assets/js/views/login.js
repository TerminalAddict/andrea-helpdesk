/**
 * Login View - Agent and Customer portal login
 */
const LoginView = {
    _supportFormStartedAt: null,
    _supportChallengeToken: '',
    _supportRecaptchaReady: null,
    render(params) {
        if (params?.section === 'support-form') {
            return this.renderSupportForm(params);
        }
        const activeSection = params?.section === 'portal' ? 'customer' : 'agent';
        return `
        <div class="terminal-login-shell min-vh-100 d-flex align-items-center justify-content-center">
            <div class="card terminal-login-card shadow-sm">
                <div class="card-body p-4">
                    <div class="text-center mb-4">
                        ${App.settings.logo_url
                            ? `<img src="${App.escapeHtml(App.settings.logo_url)}" alt="${App.escapeHtml(App.appName)}" style="max-height:80px;max-width:240px;object-fit:contain;">`
                            : `<i class="bi bi-headset text-primary" style="font-size:3rem;"></i>`}
                        <h4 class="mt-2 mb-0 fw-bold">${App.escapeHtml(App.appName)}</h4>
                    </div>

                    <ul class="nav nav-tabs mb-3" id="loginTabs">
                        <li class="nav-item">
                            <button class="nav-link ${activeSection === 'agent' ? 'active' : ''}" data-tab="agent">Agent Login</button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link ${activeSection === 'customer' ? 'active' : ''}" data-tab="customer">Customer Portal</button>
                        </li>
                    </ul>

                    <!-- Agent Login -->
                    <div id="tab-agent" ${activeSection === 'agent' ? '' : 'style="display:none;"'}>
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
                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="agent-remember" checked>
                                <label class="form-check-label text-muted" for="agent-remember">Remember me</label>
                            </div>
                            <button type="submit" class="btn btn-primary w-100" id="agent-login-btn">
                                <span class="spinner-border spinner-border-sm d-none me-2" id="agent-spinner"></span>
                                Sign In
                            </button>
                        </form>
                    </div>

                    <!-- Customer Portal -->
                    <div id="tab-customer" ${activeSection === 'customer' ? '' : 'style="display:none;"'}>
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

    renderSupportForm(params) {
        const isEmbed = String(params?.embed || '') === '1';
        return `
        <div class="${isEmbed ? 'p-2' : 'terminal-login-shell min-vh-100 d-flex align-items-center justify-content-center'}">
            <div class="card terminal-login-card shadow-sm ${isEmbed ? 'mx-auto border-0' : ''}" style="max-width:720px;width:100%;">
                <div class="card-body p-4">
                    <div class="text-center mb-4">
                        ${App.settings.logo_url
                            ? `<img src="${App.escapeHtml(App.settings.logo_url)}" alt="${App.escapeHtml(App.appName)}" style="max-height:80px;max-width:240px;object-fit:contain;">`
                            : `<i class="bi bi-life-preserver text-primary" style="font-size:3rem;"></i>`}
                        <h4 class="mt-2 mb-1 fw-bold">${App.escapeHtml(App.appName)}</h4>
                        <p class="text-muted mb-0">Submit a support request</p>
                    </div>

                    <div id="support-form-error" class="alert alert-danger d-none"></div>
                    <div id="support-form-success" class="alert alert-success d-none"></div>

                    <form id="public-support-form">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Name</label>
                                <input type="text" class="form-control" id="support-form-name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" id="support-form-email" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Subject</label>
                                <input type="text" class="form-control" id="support-form-subject" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Message</label>
                                <textarea class="form-control" id="support-form-message" rows="7" required></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Attachments</label>
                                <input type="file" class="form-control" id="support-form-files" multiple>
                                <div class="form-text">
                                    Optional. You can attach screenshots, logs, or documents.
                                    <a href="#" id="support-form-attachment-limits-link" class="ms-1">Attachment limits</a>
                                </div>
                            </div>
                            <div class="col-12 d-none">
                                <label class="form-label">Website</label>
                                <input type="text" class="form-control" id="support-form-website" tabindex="-1" autocomplete="off">
                            </div>
                            <div class="col-12" id="support-form-human-wrap"></div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 mt-4" id="support-form-submit">
                            <span class="spinner-border spinner-border-sm d-none me-2" id="support-form-spinner"></span>
                            Submit Support Request
                        </button>
                        <div class="small text-muted text-center mt-3 d-none" id="support-form-recaptcha-notice">
                            Protected by reCAPTCHA — <a href="https://policies.google.com/privacy" target="_blank" rel="noopener noreferrer">Privacy</a> &amp; <a href="https://policies.google.com/terms" target="_blank" rel="noopener noreferrer">Terms</a> apply.
                        </div>
                    </form>
                </div>
            </div>
        </div>`;
    },

    init(params) {
        if (params?.section === 'support-form') {
            this.initSupportForm();
            return;
        }
        const activeSection = params?.section === 'portal' ? 'customer' : 'agent';
        // Tab switching
        $('[data-tab]').on('click', function() {
            const tab = $(this).data('tab');
            $('[data-tab]').removeClass('active');
            $(this).addClass('active');
            $('#tab-agent, #tab-customer').hide();
            $('#tab-' + tab).show();
            App.navigate(tab === 'customer' ? '/login/portal' : '/login/agent');
        });

        // Pre-fill email if previously remembered
        const rememberedEmail = localStorage.getItem('andrea_remembered_email');
        if (rememberedEmail) {
            $('#agent-email').val(rememberedEmail);
        }

        // Agent login
        $('#agent-login-form').on('submit', async (e) => {
            e.preventDefault();
            const email    = $('#agent-email').val();
            const password = $('#agent-password').val();
            const remember = $('#agent-remember').is(':checked');

            $('#agent-error').addClass('d-none');
            $('#agent-spinner').removeClass('d-none');
            $('#agent-login-btn').prop('disabled', true);

            try {
                const res = await API.post('/auth/login', { email, password, type: 'agent' });
                API.setTokens(res.data.access_token, res.data.refresh_token, remember);
                if (remember) {
                    localStorage.setItem('andrea_remembered_email', email);
                } else {
                    localStorage.removeItem('andrea_remembered_email');
                }
                API.currentUser = res.data.user;
                API.currentUser.type = 'agent';
                App.applyTheme(API.currentUser.theme || 'light');
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
        const query = new URLSearchParams(window.location.hash.split('?')[1] || '');
        const token  = query.get('token');
        const email  = query.get('email');
        if (token && email) {
            this.verifyMagicLink(token, email);
        }

        if (activeSection === 'customer') {
            $('#customer-email').trigger('focus');
        } else {
            $('#agent-email').trigger('focus');
        }
    },

    initSupportForm() {
        this._supportFormStartedAt = Date.now();
        this.initSupportHumanCheck();
        this.ensureSupportAttachmentLimitsModal();
        $(document).off('click.supportFormLimits').on('click.supportFormLimits', '#support-form-attachment-limits-link', (e) => {
            e.preventDefault();
            this.showSupportAttachmentLimits();
        });

        $('#public-support-form').on('submit', async (e) => {
            e.preventDefault();
            const name = $('#support-form-name').val().trim();
            const email = $('#support-form-email').val().trim();
            const subject = $('#support-form-subject').val().trim();
            const message = $('#support-form-message').val().trim();
            const files = document.getElementById('support-form-files').files;

            $('#support-form-error, #support-form-success').addClass('d-none');
            $('#support-form-spinner').removeClass('d-none');
            $('#support-form-submit').prop('disabled', true);

            try {
                const fd = new FormData();
                fd.append('name', name);
                fd.append('email', email);
                fd.append('subject', subject);
                fd.append('message', message);
                fd.append('website', $('#support-form-website').val().trim());
                fd.append('started_at', String(this._supportFormStartedAt || Date.now()));

                const publicSettings = App.settings || {};
                if (publicSettings.support_form_recaptcha_site_key) {
                    const token = await this.getSupportRecaptchaToken(publicSettings.support_form_recaptcha_site_key);
                    fd.append('recaptcha_token', token);
                } else {
                    fd.append('human_check_token', this._supportChallengeToken || '');
                    fd.append('human_check_answer', $('#support-form-human-answer').val().trim());
                }

                for (const file of Array.from(files || [])) {
                    fd.append('file[]', file);
                }

                await API.upload('/support-form', fd);
                $('#support-form-success').text('Your support request has been submitted. We will be in touch by email.').removeClass('d-none');
                $('#public-support-form')[0].reset();
                this._supportFormStartedAt = Date.now();
                await this.initSupportHumanCheck();
            } catch (err) {
                $('#support-form-error').text(err.message || 'Failed to submit support request').removeClass('d-none');
            } finally {
                $('#support-form-spinner').addClass('d-none');
                $('#support-form-submit').prop('disabled', false);
            }
        });
    },

    async initSupportHumanCheck() {
        const publicSettings = App.settings || {};
        if (publicSettings.support_form_recaptcha_site_key) {
            $('#support-form-human-wrap').html('<div class="small text-muted">reCAPTCHA protection is active for this form.</div>');
            $('#support-form-recaptcha-notice').removeClass('d-none');
            return;
        }

        try {
            const res = await API.get('/support-form/challenge');
            this._supportChallengeToken = res.data.token || '';
            $('#support-form-human-wrap').html(`
                <label class="form-label">Human Check</label>
                <div class="input-group">
                    <span class="input-group-text">${App.escapeHtml(res.data.question || 'Verification')}</span>
                    <input type="text" class="form-control" id="support-form-human-answer" placeholder="Your answer" required>
                </div>
                <div class="form-text">Answer the question to show that you are a real visitor.</div>
            `);
            $('#support-form-recaptcha-notice').addClass('d-none');
        } catch (e) {
            $('#support-form-human-wrap').html('<div class="alert alert-warning py-2">Human verification could not be loaded. Please refresh and try again.</div>');
        }
    },

    async getSupportRecaptchaToken(siteKey) {
        await this.ensureSupportRecaptcha(siteKey);
        return new Promise((resolve, reject) => {
            window.grecaptcha.ready(() => {
                window.grecaptcha.execute(siteKey, { action: 'support_form_submit' })
                    .then(resolve)
                    .catch(() => reject(new Error('reCAPTCHA verification failed')));
            });
        });
    },

    async ensureSupportRecaptcha(siteKey) {
        if (window.grecaptcha) {
            return;
        }
        if (!this._supportRecaptchaReady) {
            this._supportRecaptchaReady = new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = 'https://www.google.com/recaptcha/api.js?render=' + encodeURIComponent(siteKey);
                script.async = true;
                script.defer = true;
                script.onload = () => resolve();
                script.onerror = () => reject(new Error('Failed to load reCAPTCHA'));
                document.head.appendChild(script);
            });
        }
        return this._supportRecaptchaReady;
    },

    showSupportAttachmentLimits() {
        const settings = App.settings || {};
        const maxBytes = parseInt(settings.support_form_attachment_max_bytes || 0, 10) || 0;
        const maxMb = maxBytes ? (maxBytes / 1048576).toFixed(1).replace(/\.0$/, '') : '10';
        const mimeTypes = Array.isArray(settings.support_form_attachment_mime_types) ? settings.support_form_attachment_mime_types : [];
        const list = mimeTypes.length
            ? `<ul class="small mb-0 ps-3">${mimeTypes.map(type => `<li>${App.escapeHtml(type)}</li>`).join('')}</ul>`
            : '<p class="small mb-0">Common images, documents, archives, and media files are allowed.</p>';

        $('#supportAttachmentLimitsModalBody').html(`
            <p class="mb-3">Maximum file size per attachment: <strong>${App.escapeHtml(maxMb)} MB</strong></p>
            <div>
                <div class="fw-semibold small text-muted mb-2">Allowed MIME Types</div>
                ${list}
            </div>
        `);

        bootstrap.Modal.getOrCreateInstance(document.getElementById('supportAttachmentLimitsModal')).show();
    },

    ensureSupportAttachmentLimitsModal() {
        if (document.getElementById('supportAttachmentLimitsModal')) {
            return;
        }

        document.body.insertAdjacentHTML('beforeend', `
            <div class="modal fade" id="supportAttachmentLimitsModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="bi bi-paperclip me-2"></i>Attachment Limits
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body" id="supportAttachmentLimitsModalBody"></div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>
        `);
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
