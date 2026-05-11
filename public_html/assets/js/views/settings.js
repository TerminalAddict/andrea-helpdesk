/**
 * Admin: Settings View
 */
const SettingsView = {
    settings: {},
    agents: [],
    adminSections: [
        { key: 'general', label: 'General' },
        { key: 'branding', label: 'Branding' },
        { key: 'email', label: 'Email / SMTP' },
        { key: 'autoresponse', label: 'Auto-Response' },
        { key: 'imap', label: 'IMAP Polling' },
        { key: 'slack', label: 'Slack' },
        { key: 'notifications', label: 'Notifications' },
        { key: 'support-form', label: 'Support Form' },
    ],

    getAdminSectionKeys() {
        return this.adminSections.map(section => section.key);
    },

    getAdminSectionLabel(key) {
        return (this.adminSections.find(section => section.key === key) || {}).label || 'Settings';
    },

    render(params) {
        const activeSection = this.getAdminSectionKeys().includes(params?.section) ? params.section : 'general';
        const adminTabs = this.adminSections.map((section) => `
                <li class="nav-item">
                    <a class="nav-link${section.key === activeSection ? ' active' : ''}" href="#/admin/settings/${section.key}" data-section="${section.key}">
                        ${section.label}
                    </a>
                </li>`).join('');
        const adminOpts = this.adminSections.map((section) =>
            `<option value="${section.key}" ${section.key === activeSection ? 'selected' : ''}>${section.label}</option>`
        ).join('');
        return `
        <div class="container-fluid terminal-screen terminal-screen-settings p-4 terminal-compact">
            <h4 class="terminal-heading mb-3"><i class="bi bi-sliders me-2"></i>Settings</h4>

            <ul class="nav nav-tabs d-none d-md-flex" id="settings-tabs">
                ${adminTabs}
            </ul>

            <select class="form-select mb-2 d-md-none" id="settings-tab-select">
                ${adminOpts}
            </select>

            <div id="settings-content">
                <div class="text-center py-5 text-muted">
                    <div class="spinner-border"></div><p class="mt-2">Loading…</p>
                </div>
            </div>
        </div>`;
    },

    async init(params) {
        const requestedSection = params && params.section ? String(params.section) : 'general';
        const initialSection = this.getAdminSectionKeys().includes(requestedSection) ? requestedSection : 'general';
        try {
            const results = await Promise.all([
                API.get('/auth/me'),
                API.get('/settings/public'),
                API.get('/admin/settings'),
                API.get('/agents'),
                API.get('/admin/settings/push-status')
            ]);
            this.currentAgent = (results[0].data && results[0].data.user) || {};
            this.settings = results[2].data || {};
            this.publicSettings = results[1].data || {};
            this.agents   = results[3].data || [];
            this.pushStatus = results[4].data || {};
            this.renderTab(initialSection);
            this.bindTabSwitching();
        } catch (e) {
            $('#settings-content').html('<div class="alert alert-danger">' + App.escapeHtml(e.message) + '</div>');
        }
    },

    bindTabSwitching() {
        $('#settings-tab-select').on('change', (e) => {
            App.navigate('/admin/settings/' + $(e.currentTarget).val());
        });
    },

    renderTab(tab) {
        $('#settings-tabs .nav-link').removeClass('active').filter(`[data-section="${tab}"]`).addClass('active');
        $('#settings-tab-select').val(tab);
        const s = this.settings;
        let html = '';

        if (tab === 'general') {
            html = this.form('general', [
                { key: 'company_name',   label: 'Application Name',  type: 'text',   value: s.company_name || 'Andrea Helpdesk' },
                { key: 'app_url',        label: 'Application URL',   type: 'text',   value: s.app_url || '' },
                { key: 'timezone',       label: 'Timezone',          type: 'text',   value: s.timezone || 'Pacific/Auckland', hint: 'e.g. Pacific/Auckland, UTC' },
                { key: 'date_format',    label: 'Date Format',       type: 'text',   value: s.date_format || 'Y-m-d H:i', hint: 'PHP date() format string' },
                { key: 'ticket_prefix',  label: 'Ticket Number Prefix', type: 'text', value: s.ticket_prefix || 'HD' },
                { key: 'imap_poll_mode', label: 'IMAP Polling Mode', type: 'select', value: s.imap_poll_mode || 'cron',
                  options: [['cron','Cron Job (recommended)'],['web','Web Triggered']] },
                { key: 'sla_enabled',    label: 'Enable SLA escalation', type: 'checkbox', value: s.sla_enabled },
                { key: 'sla_high_after_days', label: 'Raise to High after', type: 'number', value: s.sla_high_after_days ?? 3, hint: 'Days without attention before a ticket becomes high priority.' },
                { key: 'sla_overdue_after_days', label: 'Raise to Overdue after', type: 'number', value: s.sla_overdue_after_days ?? 2, hint: 'Additional days without attention after the high stage before the ticket becomes overdue.' },
                { key: 'sla_notify_scope', label: 'SLA reminder recipients', type: 'select', value: s.sla_notify_scope || 'all',
                  options: [['all','All active agents'],['specific','Specific agents only']] },
            ]) + this.renderSlaRecipients();
        } else if (tab === 'branding') {
            html = this.form('branding', [
                { key: 'logo_url',              label: 'Logo URL',       type: 'text',  value: s.logo_url || '', hint: 'URL to your logo image (displayed in the navbar)' },
                { key: 'favicon_url',           label: 'Favicon URL',    type: 'text',  value: s.favicon_url || '', hint: 'URL to a .ico, .png, or .svg (16×16 or 32×32 recommended). Applied instantly to all browser tabs.' },
                { key: 'primary_color',         label: 'Primary Colour', type: 'color', value: s.primary_color || '#0d6efd' },
                { key: 'support_email_display', label: 'Support Email (displayed)', type: 'email', value: s.support_email_display || '' },
            ]);
        } else if (tab === 'email') {
            html = this.form('email', [
                { key: 'smtp_host',       label: 'SMTP Host',       type: 'text',     value: s.smtp_host || '' },
                { key: 'smtp_port',       label: 'SMTP Port',       type: 'number',   value: s.smtp_port || '587' },
                { key: 'smtp_encryption', label: 'Encryption',      type: 'select',   value: s.smtp_encryption || 'tls',
                  options: [['tls','TLS (STARTTLS)'],['ssl','SSL'],['none','None']] },
                { key: 'smtp_username',   label: 'SMTP Username',   type: 'email',    value: s.smtp_username || '', autocomplete: 'username' },
                { key: 'smtp_password',   label: 'SMTP Password',   type: 'password', value: '', placeholder: 'Leave blank to keep current' },
                { key: 'smtp_from_address', label: 'From Email',    type: 'email',    value: s.smtp_from_address || '' },
                { key: 'smtp_from_name',  label: 'From Name',       type: 'text',     value: s.smtp_from_name || '' },
                { key: 'reply_to_address', label: 'Reply-To Email', type: 'email',    value: s.reply_to_address || '', hint: 'Replies to this address create/update tickets' },
                { key: 'global_signature', label: 'Email Signature', type: 'textarea', value: s.global_signature || '', hint: 'Use {{agent_name}} as placeholder' },
                { key: 'include_portal_link_in_customer_emails', label: 'Include a link to the customer portal in all email communications', type: 'checkbox', value: s.include_portal_link_in_customer_emails },
                { key: 'notify_agent_on_new_ticket', label: 'Notify agents on new ticket', type: 'checkbox', value: s.notify_agent_on_new_ticket },
                { key: 'notify_agent_on_new_reply',  label: 'Notify agents on new customer reply', type: 'checkbox', value: s.notify_agent_on_new_reply },
            ]);
        } else if (tab === 'autoresponse') {
            html = this.form('autoresponse', [
                { key: 'auto_response_enabled', label: 'Enable Auto-Response',    type: 'checkbox', value: s.auto_response_enabled },
                { key: 'auto_response_subject', label: 'Auto-Response Subject',   type: 'text',     value: s.auto_response_subject || 'Re: {{subject}} [{{ticket_number}}]' },
                { key: 'auto_response_body',    label: 'Auto-Response Body',      type: 'textarea', value: s.auto_response_body || '',
                  hint: 'Placeholders: {{customer_name}}, {{ticket_number}}, {{subject}}, {{app_name}}' },
            ]);
        } else if (tab === 'imap') {
            $('#settings-content').html(this.renderImapPanel());
            this.loadImapAccounts();
            return;
        } else if (tab === 'slack') {
            const emojiPicks = [
                ['😇','angel'],['🥸','disguised_face'],['🤖','robot_face'],
                ['🔔','bell'],['💬','speech_balloon'],['🎫','ticket'],
                ['📋','clipboard'],['⭐','star'],['⚡','zap'],['🎧','headphones'],
            ].map(([em, code]) =>
                `<button type="button" class="btn btn-sm btn-outline-secondary me-1 mb-1 slack-emoji-pick" data-code=":${code}:" title=":${code}:">${em} <span class="text-muted" style="font-size:.7rem;">:${code}:</span></button>`
            ).join('');
            html = this.form('slack', [
                { key: 'slack_enabled',       label: 'Enable Slack Notifications',   type: 'checkbox', value: s.slack_enabled },
                { key: 'slack_webhook_url',   label: 'Webhook URL',                  type: 'text',     value: s.slack_webhook_url || '' },
                { key: 'slack_channel',       label: 'Channel',                      type: 'text',     value: s.slack_channel || '#helpdesk' },
                { key: 'slack_on_new_ticket', label: 'Notify on new tickets',        type: 'checkbox', value: s.slack_on_new_ticket },
                { key: 'slack_on_assign',     label: 'Notify on ticket assignment',  type: 'checkbox', value: s.slack_on_assign },
                { key: 'slack_on_new_reply',  label: 'Notify on new customer reply', type: 'checkbox', value: s.slack_on_new_reply },
                { key: 'slack_unfurl_links',  label: 'Show link previews',           type: 'checkbox', value: s.slack_unfurl_links !== undefined ? s.slack_unfurl_links : true,
                  hint: 'When enabled, Slack will expand ticket links into a rich preview card.' },
                { key: 'slack_username',      label: 'Bot display name',             type: 'text',     value: s.slack_username || '',
                  placeholder: 'e.g. Helpdesk Bot',
                  hint: 'The name shown on Slack messages from this integration. Leave blank to use the webhook\'s default name.' },
                { key: 'slack_icon_url',      label: 'Bot icon — image URL',         type: 'text',     value: s.slack_icon_url || '',
                  placeholder: 'https://example.com/icon.png',
                  hint: 'URL of an image to use as the Slack bot icon. If set, this overrides the emoji icon below.' },
                { key: 'slack_icon_emoji',    label: 'Bot icon — emoji',             type: 'text',     value: s.slack_icon_emoji || '',
                  placeholder: ':robot_face:',
                  hint_html: `<div class="mt-2 mb-1">Quick pick:</div>${emojiPicks}<div class="form-text mt-1">Or type any emoji code set up in your Slack workspace, e.g. <code>:paul:</code></div>` },
            ]);
        } else if (tab === 'notifications') {
            const status = this.pushStatus || {};
            const diagnostics = status.diagnostics || {};
            const extensions = diagnostics.php_extensions || {};
            const statusClass = status.status === 'configured' ? 'success' : (status.status === 'invalid' ? 'danger' : 'secondary');
            const statusLabel = status.message || 'Push notification status unknown.';
            const extensionBadge = (label, ok) => `<span class="badge ${ok ? 'text-bg-success' : 'text-bg-danger'} me-1">${App.escapeHtml(label)} ${ok ? 'OK' : 'Missing'}</span>`;
            html = `
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-semibold py-2">
                        <i class="bi bi-bell me-2"></i>Browser Push Notifications
                    </div>
                    <div class="card-body py-3">
                        <div class="alert alert-${statusClass} py-2">
                            <div class="fw-semibold">${App.escapeHtml(statusLabel)}</div>
                            <div class="small mt-1">Public key: ${status.public_key_present ? 'present' : 'missing'} · Private key: ${status.private_key_present ? 'present' : 'missing'}</div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-sm-4">
                                <div class="border rounded p-2 h-100 bg-light">
                                    <div class="small text-muted">Active subscriptions</div>
                                    <div class="fs-5 fw-semibold">${App.escapeHtml(String(diagnostics.subscription_count || 0))}</div>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="border rounded p-2 h-100 bg-light">
                                    <div class="small text-muted">Subscribed agents</div>
                                    <div class="fs-5 fw-semibold">${App.escapeHtml(String(diagnostics.subscribed_agent_count || 0))}</div>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="border rounded p-2 h-100 bg-light">
                                    <div class="small text-muted">Last seen</div>
                                    <div class="small fw-semibold">${diagnostics.last_subscription_seen_at ? App.escapeHtml(App.formatDate(diagnostics.last_subscription_seen_at)) : 'Never'}</div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="small fw-semibold mb-1">Server requirements</div>
                            ${extensionBadge('web-push', !!diagnostics.web_push_dependency)}
                            ${extensionBadge('curl', !!extensions.curl)}
                            ${extensionBadge('mbstring', !!extensions.mbstring)}
                            ${extensionBadge('openssl', !!extensions.openssl)}
                            ${extensionBadge('prime256v1', !!diagnostics.openssl_prime256v1)}
                        </div>

                        ${diagnostics.last_send_failure ? `
                            <div class="alert alert-warning py-2">
                                <div class="fw-semibold small">Last push send failure ${diagnostics.last_send_failed_at ? App.escapeHtml(App.formatDate(diagnostics.last_send_failed_at)) : ''}</div>
                                <div class="small font-monospace">${App.escapeHtml(diagnostics.last_send_failure)}</div>
                            </div>
                        ` : ''}

                        <p class="text-muted small mb-3">
                            VAPID keys identify this helpdesk to browser push services. The public key is sent to browsers; the private key is encrypted at rest and never exposed to frontend code.
                        </p>

                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">PUSH_VAPID_PUBLIC_KEY</label>
                                <input type="text" class="form-control font-monospace" id="s-push_vapid_public_key" value="${App.escapeHtml(s.push_vapid_public_key || '')}">
                            </div>
                            <div class="col-12">
                                <label class="form-label">PUSH_VAPID_PRIVATE_KEY</label>
                                <input type="password" class="form-control font-monospace" id="s-push_vapid_private_key" value="" placeholder="Leave blank to keep current">
                            </div>
                            <div class="col-12">
                                <label class="form-label">PUSH_VAPID_SUBJECT</label>
                                <input type="text" class="form-control" id="s-push_vapid_subject" value="${App.escapeHtml(s.push_vapid_subject || '')}" placeholder="mailto:support@example.com or https://helpdesk.example.com">
                                <div class="form-text">Use a contact email as <code>mailto:name@example.com</code> or this application's HTTPS origin.</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">PWA / Notification Icon URL</label>
                                <input type="text" class="form-control" id="s-pwa_icon_url" value="${App.escapeHtml(s.pwa_icon_url || '')}" placeholder="Leave blank to use Favicon URL">
                                <div class="form-text">Optional. Use a square HTTPS or root-relative PNG/SVG with safe padding for installed app icons and push notifications. If empty, the Branding → Favicon URL is used.</div>
                            </div>
                        </div>

                        <div class="d-flex gap-2 flex-wrap mt-3">
                            <button class="btn btn-primary btn-save-settings" data-tab="notifications">
                                <i class="bi bi-save me-1"></i>Save Push Settings
                            </button>
                            <button class="btn btn-outline-secondary" id="btn-generate-push-keys">
                                <i class="bi bi-key me-1"></i>Generate Keys
                            </button>
                            <button class="btn btn-outline-primary" id="btn-admin-test-push">
                                <i class="bi bi-send me-1"></i>Send Test Push To Me
                            </button>
                        </div>
                    </div>
                </div>`;
        } else if (tab === 'support-form') {
            const appUrl = (this.settings.app_url || window.location.origin || '').replace(/\/$/, '');
            const formUrl = `${appUrl}/#/login/support-form`;
            const embedUrl = `${appUrl}/support-form/embed`;
            const allowedOrigins = Array.isArray(s.support_form_allowed_origins)
                ? s.support_form_allowed_origins.join('\n')
                : '';
            const iframeSnippet = `<iframe src="${embedUrl}" title="Andrea Helpdesk support form" style="width:100%;max-width:720px;height:860px;border:0;border-radius:12px;overflow:hidden;" loading="lazy"></iframe>`;
            html = `
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-semibold py-2">
                        <i class="bi bi-life-preserver me-2"></i>Website Support Form
                    </div>
                    <div class="card-body py-3">
                        <p class="text-muted mb-3">This public support form creates tickets in Andrea Helpdesk with channel <strong>Web</strong>. It can be linked directly or embedded in another website.</p>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">reCAPTCHA v3 Site Key</label>
                                <input type="text" class="form-control" id="s-support_form_recaptcha_site_key" value="${App.escapeHtml(s.support_form_recaptcha_site_key || '')}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">reCAPTCHA v3 Secret Key</label>
                                <input type="password" class="form-control" id="s-support_form_recaptcha_secret_key" value="" placeholder="Leave blank to keep current">
                            </div>
                        </div>
                        <div class="form-text mb-3">
                            If both keys are configured, the public support form will use reCAPTCHA v3. If not, it falls back to a built-in human verification challenge.
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Allow This Form To Be Embedded In The Following Websites</label>
                            <textarea class="form-control" id="s-support_form_allowed_origins" rows="4" placeholder="https://example.com&#10;https://www.example.com">${App.escapeHtml(allowedOrigins)}</textarea>
                            <div class="form-text">Enter one origin per line. Use the origin only, for example <code>https://www.example.com</code>. These origins will be added to the support form embed page's <code>Content-Security-Policy: frame-ancestors</code> allowlist.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small text-muted mb-1">Direct URL</label>
                            <input type="text" class="form-control" readonly value="${App.escapeHtml(formUrl)}">
                        </div>

                        <div class="mb-3">
                            <label class="form-label small text-muted mb-1">Iframe Embed Snippet</label>
                            <textarea class="form-control" rows="4" readonly>${App.escapeHtml(iframeSnippet)}</textarea>
                            <div class="form-text">Paste this iframe into your website to embed the support form.</div>
                        </div>

                        <div class="d-flex gap-2 flex-wrap mb-3">
                            <a class="btn btn-primary btn-sm" href="#/login/support-form" target="_blank" rel="noopener">
                                <i class="bi bi-box-arrow-up-right me-1"></i>Open Support Form
                            </a>
                            <a class="btn btn-outline-secondary btn-sm" href="${App.escapeHtml(embedUrl)}" target="_blank" rel="noopener">
                                <i class="bi bi-window-sidebar me-1"></i>Open Embed Preview
                            </a>
                        </div>

                        <div class="ratio ratio-4x3 border rounded overflow-hidden bg-light">
                            <iframe src="${App.escapeHtml(embedUrl)}" title="Support form preview" style="border:0;"></iframe>
                        </div>

                        <div class="mt-3">
                            <button class="btn btn-primary btn-save-settings" data-tab="support-form">
                                <i class="bi bi-save me-1"></i>Save Settings
                            </button>
                        </div>
                    </div>
                </div>`;
        }

        $('#settings-content').html(html);

        // Bind save
        $('.btn-save-settings').on('click', (e) => {
            this.save($(e.currentTarget).data('tab'));
        });

        // Rich editors for textarea fields
        if (tab === 'email') {
            RichEditor.init('s-global_signature', { value: s.global_signature || '' });
        }
        if (tab === 'autoresponse') {
            RichEditor.init('s-auto_response_body', { value: s.auto_response_body || '' });
        }

        // Slack emoji quick-pick buttons
        if (tab === 'slack') {
            $('#settings-content').on('click', '.slack-emoji-pick', function() {
                $('#s-slack_icon_emoji').val($(this).data('code'));
            });
        }

        if (tab === 'notifications') {
            $('#btn-generate-push-keys').on('click', () => this.generatePushKeys());
            $('#btn-admin-test-push').on('click', () => this.sendTestPush('#btn-admin-test-push'));
        }

        // Add test SMTP button on email tab
        if (tab === 'email') {
            $('.btn-save-settings').after(
                ' <button class="btn btn-outline-secondary btn-test-smtp ms-2"><i class="bi bi-envelope me-1"></i>Test SMTP</button>'
            );
            $('.btn-test-smtp').on('click', () => this.testSmtp());
        }

        // Add IMAP poll mode instructions on general tab
        if (tab === 'general') {
        const appUrl  = this.settings.app_url || window.location.origin;
        const cronCmd = `* * * * * php /path/to/helpdesk/bin/imap-poll.php >> /path/to/helpdesk/storage/logs/imap.log 2>&1`;
        $('.btn-save-settings').closest('.card-body').find('#s-imap_poll_mode').closest('.mb-3').after(`
                <div id="imap-poll-info-cron" class="mb-3 d-none">
                    <div class="alert alert-secondary py-2 mb-0">
                        <div class="fw-semibold small mb-1"><i class="bi bi-terminal me-1"></i>Cron Job Setup</div>
                        <p class="small mb-2">Add the following line to your server crontab (<code>crontab -e</code> as the web server user, or use <code>make cron-install-production</code> from your local machine):</p>
                        <pre class="imap-cron-sample user-select-all mb-2">${App.escapeHtml(cronCmd)}</pre>
                        <p class="small mb-1"><i class="bi bi-folder2-open me-1"></i>Example app path: <code>${App.escapeHtml(appUrl)}/bin/imap-poll.php</code> (adjust for your install)</p>
                        <p class="small mt-2 mb-0 text-muted">Replace <code>/path/to/helpdesk/</code> with the actual path to this application on your server. The script uses a file lock so overlapping runs are safe.</p>
                    </div>
                </div>
                <div id="imap-poll-info-web" class="mb-3 d-none">
                    <div class="alert alert-info py-2 mb-0">
                        <div class="fw-semibold small mb-1"><i class="bi bi-globe me-1"></i>Web Triggered Mode</div>
                        <p class="small mb-0">The IMAP poller will be triggered automatically in the background whenever an agent visits the helpdesk. No cron job is required, but polling only occurs while at least one agent has the app open. A file lock prevents overlapping runs.</p>
                    </div>
                </div>`);

            const updatePollInfo = (val) => {
                $('#imap-poll-info-cron').toggleClass('d-none', val !== 'cron');
                $('#imap-poll-info-web').toggleClass('d-none', val !== 'web');
            };
            $('#s-imap_poll_mode').on('change', function() { updatePollInfo(this.value); });
            updatePollInfo($('#s-imap_poll_mode').val());
            $('#s-sla_notify_scope').on('change', () => this.syncSlaRecipientVisibility());
            this.syncSlaRecipientVisibility();

            // Version & update check card
            $('#settings-content').append(`
                <div class="card border-0 shadow-sm mt-3" id="version-card">
                    <div class="card-header bg-white fw-semibold py-2">
                        <i class="bi bi-box-seam me-2"></i>Version &amp; Updates
                    </div>
                    <div class="card-body py-3">
                        <div class="d-flex align-items-center gap-3 flex-wrap">
                            <div>
                                <div class="small text-muted">Installed version</div>
                                <div class="fw-semibold" id="installed-version"><span class="spinner-border spinner-border-sm"></span></div>
                            </div>
                            <button class="btn btn-outline-secondary btn-sm" id="btn-check-update">
                                <i class="bi bi-arrow-repeat me-1"></i>Check for Updates
                            </button>
                        </div>
                        <div class="alert alert-secondary py-2 mt-3 mb-0">
                            <div class="fw-semibold small mb-1"><i class="bi bi-info-circle me-1"></i>Shared Hosting And File Ownership</div>
                            <div class="small text-muted mb-1">
                                The in-app updater must be able to overwrite existing application files. On shared hosting this may not be possible if PHP cannot write to files owned by your hosting account.
                            </div>
                            <div class="small text-muted mb-0">
                                If preflight reports overwrite or permission failures, use SFTP/rsync/file-manager deployment instead of the web updater. Avoid making the app tree world-writable.
                            </div>
                        </div>
                        <div id="update-result" class="mt-2"></div>
                    </div>
                </div>`);

            API.get('/version').then(res => {
                $('#installed-version').text(res.data.version || 'unknown');
            }).catch(() => {
                $('#installed-version').text('unknown');
            });

            $('#btn-check-update').on('click', async function() {
                const $btn    = $(this);
                const $result = $('#update-result');
                $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Checking…');
                $result.html('');
                try {
                    const checkRes = await API.post('/notifications/check-updates', { force: true });
                    if (checkRes.data && checkRes.data.created && typeof Notifications !== 'undefined') {
                        await Notifications.refreshSummary({ silent: true });
                    }
                    const res       = await API.get('/version/latest');
                    const latest    = res.data;
                    const installed = $('#installed-version').text().trim();
                    const cmp = (() => {
                        const a = (latest.version || '0').split('.').map(Number);
                        const b = (installed      || '0').split('.').map(Number);
                        for (let i = 0; i < Math.max(a.length, b.length); i++) {
                            const diff = (a[i] || 0) - (b[i] || 0);
                            if (diff !== 0) return diff;
                        }
                        return 0;
                    })();
                    if (cmp > 0) {
                        $result.html(`
                            <div class="alert alert-warning py-2 mb-0">
                                <div><i class="bi bi-arrow-up-circle me-1"></i>Version <strong>${App.escapeHtml(latest.version)}</strong> is available (released ${App.escapeHtml(latest.released)})</div>
                                <div class="mt-2 d-flex gap-2 flex-wrap">
                                    <a href="https://github.com/TerminalAddict/andrea-helpdesk" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-dark"><i class="bi bi-github me-1"></i>View on GitHub</a>
                                    <button class="btn btn-sm btn-success" id="btn-update-now"><i class="bi bi-arrow-up-circle me-1"></i>Update Now</button>
                                </div>
                            </div>`);
                        $('#btn-update-now').on('click', () => SettingsView.openUpdateModal(latest.version));
                    } else {
                        $result.html(`<div class="alert alert-success py-2 mb-0"><i class="bi bi-check-circle me-1"></i>You are running the latest version.</div>`);
                    }
                } catch (e) {
                    $result.html(`<div class="alert alert-danger py-2 mb-0"><i class="bi bi-exclamation-triangle me-1"></i>${App.escapeHtml(e.message)}</div>`);
                } finally {
                    $btn.prop('disabled', false).html('<i class="bi bi-arrow-repeat me-1"></i>Check for Updates');
                }
            });

            // Update modal (injected once into body)
            if (!$('#update-modal').length) {
                $('body').append(`
                <div class="modal fade" id="update-modal" tabindex="-1" data-bs-backdrop="static">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title"><i class="bi bi-arrow-up-circle me-2"></i>Update Andrea Helpdesk</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" id="update-modal-close"></button>
                            </div>
                            <div class="modal-body" id="update-modal-body"></div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                <button type="button" class="btn btn-success d-none" id="btn-proceed-update">
                                    <i class="bi bi-arrow-up-circle me-1"></i>Update Now
                                </button>
                            </div>
                        </div>
                    </div>
                </div>`);
            }
        }
    },

    async openUpdateModal(latestVersion) {
        const modal = new bootstrap.Modal(document.getElementById('update-modal'));
        const $body = $('#update-modal-body');
        const $proceed = $('#btn-proceed-update');
        $('#update-modal-close').prop('disabled', false);
        $proceed.addClass('d-none').off('click');
        $body.html(`<div class="text-center py-4"><div class="spinner-border"></div><p class="mt-2 text-muted">Checking prerequisites…</p></div>`);
        modal.show();

        try {
            const res    = await API.get('/update/preflight');
            const checks = res.data.checks;
            const ready  = res.data.ready;

            let rows = checks.map(c => `
                <tr>
                    <td class="text-center" style="width:2rem">
                        ${c.pass
                            ? '<i class="bi bi-check-circle-fill text-success"></i>'
                            : '<i class="bi bi-x-circle-fill text-danger"></i>'}
                    </td>
                    <td>${App.escapeHtml(c.name)}</td>
                    <td class="text-muted small">${App.escapeHtml(c.detail)}</td>
                </tr>
                ${!c.pass ? `<tr class="table-danger"><td></td><td colspan="2"><small><strong>How to fix:</strong> ${App.escapeHtml(c.fix)}</small></td></tr>` : ''}`
            ).join('');

            $body.html(`
                <p class="mb-3">Updating to version <strong>${App.escapeHtml(latestVersion)}</strong>. The update will download the latest code from GitHub and apply any new database migrations.</p>
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light"><tr><th></th><th>Check</th><th>Status</th></tr></thead>
                    <tbody>${rows}</tbody>
                </table>
                ${ready ? '<div class="alert alert-success mt-3 mb-0"><i class="bi bi-check-circle me-1"></i>All checks passed. Ready to update.</div>'
                        : '<div class="alert alert-danger mt-3 mb-0"><i class="bi bi-x-circle me-1"></i>Fix the issues above before updating.</div>'}`);

            if (ready) {
                $proceed.removeClass('d-none').on('click', () => SettingsView.runUpdate(latestVersion, modal));
            }
        } catch (e) {
            $body.html(`<div class="alert alert-danger">${App.escapeHtml(e.message)}</div>`);
        }
    },

    async runUpdate(latestVersion, modal) {
        const $body    = $('#update-modal-body');
        const $proceed = $('#btn-proceed-update');
        const $close   = $('#update-modal-close');
        $proceed.addClass('d-none');
        $close.prop('disabled', true);
        $body.html(`
            <p>Updating to <strong>${App.escapeHtml(latestVersion)}</strong>…</p>
            <div class="bg-dark text-light rounded p-3 font-monospace small" id="update-log" style="min-height:120px;max-height:320px;overflow-y:auto;">
                <div class="spinner-border spinner-border-sm me-2"></div>Running…
            </div>`);

        try {
            const res = await API.post('/update/run', {});
            const log = res.data.log || [];
            const succeeded = res.success && log[log.length - 1] === 'done';

            $('#update-log').html(log.map(l => `<div>${App.escapeHtml(l)}</div>`).join(''));

            if (succeeded) {
                $body.append(`
                    <div class="alert alert-success mt-3 mb-0">
                        <i class="bi bi-check-circle me-1"></i>
                        <strong>Update complete!</strong> Please <a href="javascript:window.location.reload()">reload the page</a> to run the new version.
                    </div>`);
            } else {
                $body.append(`<div class="alert alert-danger mt-3 mb-0"><i class="bi bi-x-circle me-1"></i>Update failed — see log above.</div>`);
            }
        } catch (e) {
            $body.append(`<div class="alert alert-danger mt-3 mb-0"><i class="bi bi-x-circle me-1"></i>${App.escapeHtml(e.message)}</div>`);
        } finally {
            $close.prop('disabled', false);
        }
    },

    form(tab, fields) {
        const inputs = fields.map(f => {
            let input = '';
            if (f.type === 'checkbox') {
                input = `<div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="s-${f.key}" ${f.value ? 'checked' : ''}>
                    <label class="form-check-label" for="s-${f.key}">${App.escapeHtml(f.label)}</label>
                </div>`;
                if (f.hint) input += `<div class="form-text">${App.escapeHtml(f.hint)}</div>`;
                return `<div class="mb-3">${input}</div>`;
            } else if (f.type === 'textarea') {
                input = `<textarea class="form-control font-monospace" id="s-${f.key}" rows="5">${App.escapeHtml(f.value || '')}</textarea>`;
            } else if (f.type === 'select') {
                const opts = f.options.map(([v, l]) =>
                    `<option value="${v}" ${f.value === v ? 'selected' : ''}>${l}</option>`
                ).join('');
                input = `<select class="form-select" id="s-${f.key}">${opts}</select>`;
            } else if (f.type === 'color') {
                input = `<input type="color" class="form-control form-control-color" id="s-${f.key}" value="${App.escapeHtml(f.value || '#0d6efd')}">`;
            } else if (f.type === 'password') {
                input = `<input type="password" class="form-control" id="s-${f.key}" value="" autocomplete="new-password"${f.placeholder ? ` placeholder="${App.escapeHtml(f.placeholder)}"` : ''}>`;
            } else {
                input = `<input type="${f.type}" class="form-control" id="s-${f.key}" value="${App.escapeHtml(f.value || '')}"${f.placeholder ? ` placeholder="${App.escapeHtml(f.placeholder)}"` : ''}${f.autocomplete ? ` autocomplete="${f.autocomplete}"` : ''}>`;
            }
            return `<div class="mb-3">
                <label class="form-label" for="s-${f.key}">${App.escapeHtml(f.label)}</label>
                ${input}
                ${f.hint ? `<div class="form-text">${App.escapeHtml(f.hint)}</div>` : ''}
                ${f.hint_html || ''}
            </div>`;
        }).join('');

        return `
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form autocomplete="off" onsubmit="return false">
                ${inputs}
                <div class="mt-3">
                    <button class="btn btn-primary btn-save-settings" data-tab="${tab}">
                        <i class="bi bi-save me-1"></i>Save Settings
                    </button>
                </div>
                </form>
            </div>
        </div>`;
    },

    renderImapPanel() {
        return `
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <p class="text-muted mb-0 small">Each account is polled every minute. Emails create or update tickets automatically.</p>
                    <button class="btn btn-primary btn-sm" id="btn-add-imap-account">
                        <i class="bi bi-plus-lg me-1"></i>Add Account
                    </button>
                </div>
                <div id="imap-accounts-list">
                    <div class="text-center py-3 text-muted"><div class="spinner-border spinner-border-sm"></div></div>
                </div>
            </div>
        </div>

        <!-- IMAP Account Modal -->
        <div class="modal fade" id="imapAccountModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="imap-modal-title">IMAP Account</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <form autocomplete="off" onsubmit="return false">
                        <input type="hidden" id="imap-account-id">
                        <input type="text" autocomplete="username" style="display:none;">
                        <div class="mb-3">
                            <label class="form-label">Account Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="imap-name" placeholder="e.g. support@mydomain.com">
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-8">
                                <label class="form-label">IMAP Host <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="imap-host" placeholder="mail.example.com">
                            </div>
                            <div class="col-4">
                                <label class="form-label">Port</label>
                                <input type="number" class="form-control" id="imap-port" value="993">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Encryption</label>
                            <select class="form-select" id="imap-encryption">
                                <option value="ssl">SSL</option>
                                <option value="tls">TLS (STARTTLS)</option>
                                <option value="none">None</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="imap-username" placeholder="user@domain.com or DOMAIN\\user" autocomplete="off">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">From Address <span class="text-muted small">(for outgoing emails)</span></label>
                            <input type="email" class="form-control" id="imap-from-address" placeholder="Leave blank to use Username" autocomplete="username">
                            <div class="form-text">Tickets tagged by this account will be sent from this address.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="imap-password" placeholder="Leave blank to keep current when editing" autocomplete="new-password">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Folder / Mailbox</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="imap-folder" value="INBOX">
                                <button class="btn btn-outline-secondary" type="button" id="btn-list-imap-folders" title="List available folders on the server">
                                    <i class="bi bi-folder2-open me-1"></i>Browse
                                </button>
                            </div>
                            <div id="imap-folder-list" class="list-group mt-1" style="display:none;max-height:180px;overflow-y:auto;"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Default Tag <span class="text-muted small">(applied to all new tickets from this account)</span></label>
                            <select class="form-select" id="imap-tag-id">
                                <option value="">No tag</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="imap-delete-after-import">
                                <label class="form-check-label" for="imap-delete-after-import">Delete email after import</label>
                            </div>
                            <div class="form-text">If unchecked, emails are marked as read instead.</div>
                        </div>
                        <div class="mb-0">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="imap-is-enabled" checked>
                                <label class="form-check-label" for="imap-is-enabled">Enabled</label>
                            </div>
                        </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline-secondary me-auto" id="btn-test-imap-account">
                            <i class="bi bi-plug me-1"></i>Test Connection
                        </button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="btn-save-imap-account">Save</button>
                    </div>
                </div>
            </div>
        </div>`;
    },

    async loadImapAccounts() {
        try {
            const [accountsRes, tagsRes] = await Promise.all([
                API.get('/admin/imap-accounts'),
                API.get('/tags'),
            ]);
            const accounts = accountsRes.data || [];
            this._imapTags = tagsRes.data || [];

            if (!accounts.length) {
                $('#imap-accounts-list').html('<p class="text-muted">No IMAP accounts configured yet.</p>');
            } else {
                const rows = accounts.map(a => {
                    const connectedStr = a.last_connected_at
                        ? `Last connected: ${App.formatDate(a.last_connected_at)}`
                        : 'Never connected';
                    const pollStr = a.last_import_at
                        ? `Last email imported: ${App.formatDate(a.last_import_at)}`
                        : 'No emails imported yet';
                    return `
                    <div class="card mb-2 ${a.is_enabled ? '' : 'opacity-50'}">
                        <div class="card-body py-2 px-3 d-flex align-items-center gap-3">
                            <div class="flex-grow-1">
                                <div class="fw-semibold">${App.escapeHtml(a.name)}</div>
                                <div class="small text-muted">${App.escapeHtml(a.username)} · ${App.escapeHtml(a.host)}:${a.port}
                                    ${a.from_address ? `· sends as <strong>${App.escapeHtml(a.from_address)}</strong>` : ''}
                                    ${a.tag_name ? `· <span class="badge bg-secondary">${App.escapeHtml(a.tag_name)}</span>` : ''}
                                    ${!a.is_enabled ? '· <span class="badge bg-light text-dark border">Disabled</span>' : ''}
                                </div>
                                <div class="small text-muted mt-1">
                                    <i class="bi bi-plug me-1"></i>${App.escapeHtml(connectedStr)}
                                    &nbsp;·&nbsp;
                                    <i class="bi bi-envelope-arrow-down me-1"></i>${App.escapeHtml(pollStr)}
                                </div>
                            </div>
                            <button class="btn btn-sm btn-outline-success btn-poll-now-imap" data-id="${a.id}" title="Poll now"><i class="bi bi-arrow-clockwise"></i></button>
                            <button class="btn btn-sm btn-outline-primary btn-edit-imap" data-id="${a.id}"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-outline-danger btn-delete-imap" data-id="${a.id}"><i class="bi bi-trash"></i></button>
                        </div>
                    </div>`;
                }).join('');
                $('#imap-accounts-list').html(rows);
            }

            $('#btn-add-imap-account').off('click').on('click', () => this.openImapModal());
            $(document).off('click.imap')
                .on('click.imap', '.btn-edit-imap',     (e) => this.openImapModal($(e.currentTarget).data('id'), accounts))
                .on('click.imap', '.btn-delete-imap',   (e) => this.deleteImapAccount($(e.currentTarget).data('id')))
                .on('click.imap', '.btn-poll-now-imap', (e) => this.pollNowImap($(e.currentTarget)));

        } catch (e) {
            $('#imap-accounts-list').html('<p class="text-danger">' + App.escapeHtml(e.message) + '</p>');
        }
    },

    openImapModal(id = null, accounts = []) {
        App.clearModalArtifacts();
        const modalEl = App.detachModal(document.getElementById('imapAccountModal'));
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        if (!modalEl.dataset.andreaImapCleanupBound) {
            modalEl.addEventListener('hidden.bs.modal', () => {
                document.body.classList.remove('modal-open');
                document.body.style.removeProperty('overflow');
                document.body.style.removeProperty('padding-right');
                document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
            });
            modalEl.dataset.andreaImapCleanupBound = '1';
        }

        modalEl.addEventListener('hide.bs.modal', () => {
            if (document.activeElement) document.activeElement.blur();
        }, { once: true });

        // Populate tag dropdown
        $('#imap-tag-id').html('<option value="">No tag</option>' +
            (this._imapTags || []).map(t => `<option value="${t.id}">${App.escapeHtml(t.name)}</option>`).join(''));

        if (id) {
            const a = accounts.find(x => x.id == id);
            $('#imap-modal-title').text('Edit IMAP Account');
            $('#imap-account-id').val(a.id);
            $('#imap-name').val(a.name);
            $('#imap-host').val(a.host);
            $('#imap-port').val(a.port);
            $('#imap-encryption').val(a.encryption);
            $('#imap-username').val(a.username);
            $('#imap-from-address').val(a.from_address || '');
            $('#imap-password').val('');
            $('#imap-folder').val(a.folder);
            $('#imap-tag-id').val(a.tag_id || '');
            $('#imap-delete-after-import').prop('checked', !!a.delete_after_import);
            $('#imap-is-enabled').prop('checked', !!a.is_enabled);
            $('#btn-test-imap-account').show();
        } else {
            $('#imap-modal-title').text('Add IMAP Account');
            $('#imap-account-id').val('');
            $('#imap-name,#imap-host,#imap-username,#imap-from-address,#imap-password').val('');
            $('#imap-port').val('993');
            $('#imap-encryption').val('ssl');
            $('#imap-folder').val('INBOX');
            $('#imap-tag-id').val('');
            $('#imap-delete-after-import').prop('checked', false);
            $('#imap-is-enabled').prop('checked', true);
            $('#btn-test-imap-account').hide();
        }

        $('#btn-save-imap-account').off('click').on('click', () => this.saveImapAccount());
        $('#btn-test-imap-account').off('click').on('click', () => this.testImapAccount());
        $('#btn-list-imap-folders').off('click').on('click', () => this.listImapFolders());
        $('#imap-folder-list').hide().empty();
        modal.show();
    },

    async saveImapAccount() {
        const id = $('#imap-account-id').val();
        const payload = {
            name:                $('#imap-name').val(),
            host:                $('#imap-host').val(),
            port:                $('#imap-port').val(),
            encryption:          $('#imap-encryption').val(),
            username:            $('#imap-username').val(),
            from_address:        $('#imap-from-address').val() || null,
            password:            $('#imap-password').val(),
            folder:              $('#imap-folder').val(),
            tag_id:              $('#imap-tag-id').val() || null,
            delete_after_import: $('#imap-delete-after-import').is(':checked'),
            is_enabled:          $('#imap-is-enabled').is(':checked'),
        };

        try {
            if (id) {
                await API.put('/admin/imap-accounts/' + id, payload);
            } else {
                await API.post('/admin/imap-accounts', payload);
            }
            bootstrap.Modal.getOrCreateInstance(document.getElementById('imapAccountModal')).hide();
            await this.loadImapAccounts();
            App.toast('IMAP account saved');
        } catch (e) { App.toast(e.message, 'error'); }
    },

    async testImapAccount() {
        const id = $('#imap-account-id').val();
        if (!id) return;
        const btn = $('#btn-test-imap-account').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Testing…');
        try {
            const res = await API.post('/admin/imap-accounts/' + id + '/test', {});
            App.toast(res.message || 'Connection successful', 'success');
        } catch (e) {
            App.toast(e.message || 'Connection failed', 'error');

        } finally {
            btn.prop('disabled', false).html('<i class="bi bi-plug me-1"></i>Test Connection');
        }
    },

    async listImapFolders() {
        const id = $('#imap-account-id').val();
        if (!id) { App.toast('Save the account first, then browse folders', 'error'); return; }
        const btn = $('#btn-list-imap-folders').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Loading…');
        $('#imap-folder-list').hide().empty();
        try {
            const res = await API.get('/admin/imap-accounts/' + id + '/list-folders');
            const folders = res.data || [];
            if (!folders.length) {
                App.toast('No folders returned by server', 'error');
                return;
            }
            const $list = $('#imap-folder-list').empty();
            folders.forEach(f => {
                $(`<a class="list-group-item list-group-item-action py-1 small" href="#">${App.escapeHtml(f)}</a>`)
                    .on('click', e => {
                        e.preventDefault();
                        $('#imap-folder').val(f);
                        $list.hide();
                    })
                    .appendTo($list);
            });
            $list.show();
        } catch (e) {
            App.toast(e.message || 'Could not list folders', 'error');
        } finally {
            btn.prop('disabled', false).html('<i class="bi bi-folder2-open me-1"></i>Browse');
        }
    },

    async pollNowImap($btn) {
        const id = $btn.data('id');
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
        try {
            const res = await API.post('/admin/imap-accounts/' + id + '/poll-now', {});
            App.toast(res.message || 'Poll complete', 'success');
            await this.loadImapAccounts(); // refresh stats
        } catch (e) {
            App.toast(e.message || 'Poll failed', 'error');
            $btn.prop('disabled', false).html('<i class="bi bi-arrow-clockwise"></i>');
        }
    },

    async deleteImapAccount(id) {
        if (!await App.confirm('Delete this IMAP account?', 'Delete Account')) return;
        try {
            await API.delete('/admin/imap-accounts/' + id);
            await this.loadImapAccounts();
            App.toast('IMAP account deleted');
        } catch (e) { App.toast(e.message, 'error'); }
    },

    renderTagsPanel() {
        return `
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="input-group mb-4" style="max-width:360px;">
                    <input type="text" class="form-control" id="new-tag-name" placeholder="New tag name…">
                    <button class="btn btn-primary" id="btn-add-tag-setting">
                        <i class="bi bi-plus-lg me-1"></i>Add Tag
                    </button>
                </div>
                <div id="tags-list">
                    <div class="text-center py-3 text-muted"><div class="spinner-border spinner-border-sm"></div></div>
                </div>
            </div>
        </div>`;
    },

    async loadTags() {
        try {
            const res  = await API.get('/tags');
            const tags = res.data || [];
            if (!tags.length) {
                $('#tags-list').html('<p class="text-muted">No tags yet.</p>');
            } else {
                const rows = tags.map(t => `
                    <div class="d-flex align-items-center gap-2 mb-2" id="tag-row-${t.id}">
                        <input type="text" class="form-control form-control-sm" style="max-width:260px;" value="${App.escapeHtml(t.name)}" id="tag-name-${t.id}">
                        <button class="btn btn-sm btn-outline-primary btn-rename-tag" data-id="${t.id}">
                            <i class="bi bi-pencil"></i> Rename
                        </button>
                        <button class="btn btn-sm btn-outline-danger btn-delete-tag" data-id="${t.id}">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>`).join('');
                $('#tags-list').html(rows);
            }

            $('#btn-add-tag-setting').off('click').on('click', () => this.addTagSetting());
            $('#new-tag-name').off('keydown').on('keydown', (e) => { if (e.key === 'Enter') this.addTagSetting(); });
            $(document).off('click.tagsetting').on('click.tagsetting', '.btn-rename-tag', (e) => {
                const id = $(e.currentTarget).data('id');
                this.renameTag(id);
            });
            $(document).on('click.tagsetting', '.btn-delete-tag', (e) => {
                const id = $(e.currentTarget).data('id');
                this.deleteTagSetting(id);
            });
        } catch (e) {
            $('#tags-list').html('<p class="text-danger">' + App.escapeHtml(e.message) + '</p>');
        }
    },

    async addTagSetting() {
        const name = $('#new-tag-name').val().trim();
        if (!name) return;
        try {
            await API.post('/tags', { name });
            $('#new-tag-name').val('');
            await this.loadTags();
            App.toast('Tag added');
        } catch (e) { App.toast(e.message, 'error'); }
    },

    async renameTag(id) {
        const name = $('#tag-name-' + id).val().trim();
        if (!name) return;
        try {
            await API.put('/tags/' + id, { name });
            App.toast('Tag renamed');
        } catch (e) { App.toast(e.message, 'error'); }
    },

    async deleteTagSetting(id) {
        if (!await App.confirm('Delete this tag? It will be removed from all tickets.', 'Delete Tag')) return;
        try {
            await API.delete('/tags/' + id);
            await this.loadTags();
            App.toast('Tag deleted');
        } catch (e) { App.toast(e.message, 'error'); }
    },

    renderProfilePanel() {
        const agent         = this.currentAgent || {};
        const globalSig     = (this.publicSettings && this.publicSettings.global_signature) || this.settings.global_signature || '';
        const globalSigHint = globalSig
            ? `<div class="mb-4">
                <label class="form-label fw-semibold">Global Signature <span class="text-muted fw-normal small">(set by admin — appended after your personal signature)</span></label>
                <div class="border rounded p-3 bg-light font-monospace small" style="white-space:pre-wrap;">${App.escapeHtml(globalSig)}</div>
               </div>`
            : '';

        return `
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6 class="mb-3">Email Signature</h6>
                <div class="mb-4">
                    <label class="form-label" for="profile-signature">Personal Signature <span class="text-muted fw-normal small">(HTML, use {{agent_name}} as placeholder)</span></label>
                    <textarea class="form-control font-monospace" id="profile-signature" rows="6">${App.escapeHtml(agent.signature || '')}</textarea>
                    <div class="form-text">This signature is added to your outgoing replies. Leave blank to use only the global signature.</div>
                </div>
                ${globalSigHint}

                <hr class="my-4">
                <h6 class="mb-3">Display Preferences</h6>
                <div class="mb-3" style="max-width:420px;">
                    <label class="form-label" for="profile-theme">Theme</label>
                    <select class="form-select" id="profile-theme">
                        <option value="light" ${(agent.theme || 'light') === 'light' ? 'selected' : ''}>Light</option>
                        <option value="dark"  ${(agent.theme || 'light') === 'dark'  ? 'selected' : ''}>Dark</option>
                    </select>
                </div>
                <div class="mb-4" style="max-width:420px;">
                    <label class="form-label" for="profile-page-size">Tickets per page</label>
                    <select class="form-select" id="profile-page-size">
                        <option value="10" ${(agent.page_size || 20) == 10 ? 'selected' : ''}>10</option>
                        <option value="20" ${(agent.page_size || 20) == 20 ? 'selected' : ''}>20</option>
                        <option value="50" ${(agent.page_size || 20) == 50 ? 'selected' : ''}>50</option>
                    </select>
                    <div class="form-text">Controls the number of rows shown on the tickets page and each dashboard block.</div>
                </div>

                <hr class="my-4">
                <h6 class="mb-3">Change Password</h6>
                <form autocomplete="off" onsubmit="return false">
                <input type="text" autocomplete="username" style="display:none;">
                <div class="mb-3" style="max-width:420px;">
                    <label class="form-label" for="profile-current-password">Current Password</label>
                    <input type="password" class="form-control" id="profile-current-password" autocomplete="current-password">
                </div>
                <div class="mb-3" style="max-width:420px;">
                    <label class="form-label" for="profile-new-password">New Password</label>
                    <input type="password" class="form-control" id="profile-new-password" autocomplete="new-password">
                    <div class="form-text">Minimum 8 characters.</div>
                </div>
                <div class="mb-4" style="max-width:420px;">
                    <label class="form-label" for="profile-confirm-password">Confirm New Password</label>
                    <input type="password" class="form-control" id="profile-confirm-password" autocomplete="new-password">
                </div>
                </form>

                <button class="btn btn-primary" id="btn-save-profile">
                    <i class="bi bi-save me-1"></i>Save Profile
                </button>
            </div>

            <div class="card border-0 shadow-sm mt-4">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-arrow-clockwise me-2"></i>Browser Cache
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">If changes to the app aren't appearing, use this to clear cached data and force a fresh reload.</p>
                    <button class="btn btn-outline-secondary" id="btn-clear-cache">
                        <i class="bi bi-trash me-1"></i>Clear Cache &amp; Reload
                    </button>
                </div>
            </div>
        </div>`;
    },

    bindProfileSave() {
        $('#btn-save-profile').on('click', () => this.saveProfile());
        $('#btn-clear-cache').on('click', async () => {
            // Preserve auth tokens across the wipe
            const accessToken  = localStorage.getItem('andrea_access_token');
            const refreshToken = localStorage.getItem('andrea_refresh_token');

            // Clear all browser storage
            localStorage.clear();
            sessionStorage.clear();

            // Clear Cache Storage API (service workers etc.)
            if ('caches' in window) {
                const keys = await caches.keys();
                await Promise.all(keys.map(k => caches.delete(k)));
            }

            // Restore auth so the user stays logged in
            if (accessToken)  localStorage.setItem('andrea_access_token', accessToken);
            if (refreshToken) localStorage.setItem('andrea_refresh_token', refreshToken);

            // Hard navigation with cache-busting query string forces a fresh HTTP fetch
            // (location.reload(true) is deprecated and browsers often ignore it)
            window.location.href = '/?_=' + Date.now() + '#/my-profile';
        });
    },

    async saveProfile() {
        const signature       = RichEditor.get('profile-signature');
        const currentPassword = $('#profile-current-password').val();
        const newPassword     = $('#profile-new-password').val();
        const confirmPassword = $('#profile-confirm-password').val();

        if (newPassword && newPassword !== confirmPassword) {
            App.toast('New passwords do not match', 'error');
            return;
        }

        const theme = $('#profile-theme').val();
        const payload = { signature, page_size: parseInt($('#profile-page-size').val()), theme };
        if (newPassword) {
            payload.current_password = currentPassword;
            payload.new_password     = newPassword;
        }

        const btn = $('#btn-save-profile').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Saving…');
        try {
            const res = await API.put('/agent/profile', payload);
            this.currentAgent = res.data || this.currentAgent;
            // Update the cached current user so page_size and theme take effect immediately
            if (API.currentUser) {
                API.currentUser.page_size = payload.page_size;
                API.currentUser.theme     = theme;
            }
            App.applyTheme(theme);
            $('#profile-current-password,#profile-new-password,#profile-confirm-password').val('');
            App.toast('Profile saved');
        } catch (e) {
            App.toast(e.message, 'error');
        } finally {
            btn.prop('disabled', false).html('<i class="bi bi-save me-1"></i>Save Profile');
        }
    },

    browserNotificationStatusLabel(agent = this.currentAgent || {}) {
        if (!('Notification' in window)) {
            return 'This browser does not support notifications';
        }

        const enabled = !!agent.browser_notifications_enabled;
        if (Notification.permission === 'denied') {
            return enabled
                ? 'Blocked by browser settings'
                : 'Permission blocked in this browser';
        }
        if (Notification.permission === 'granted' && enabled) {
            return 'Enabled for this account';
        }
        if (Notification.permission === 'granted') {
            return 'Permission granted, but disabled for this account';
        }
        return enabled ? 'Waiting for browser permission' : 'Not enabled';
    },

    refreshBrowserNotificationUi() {
        const supported = 'Notification' in window && 'serviceWorker' in navigator && 'PushManager' in window;
        const permission = ('Notification' in window) ? Notification.permission : 'unsupported';
        $('#profile-browser-notification-state').text(this.browserNotificationStatusLabel());
        const enabled = !!(this.currentAgent && this.currentAgent.browser_notifications_enabled);
        $('#btn-enable-browser-notifications').prop('disabled', !supported || (enabled && permission === 'granted'));
        $('#btn-disable-browser-notifications').prop('disabled', !supported || !enabled);
        $('#btn-test-browser-notifications').prop('disabled', !supported || !(enabled && permission === 'granted'));
    },

    async enableBrowserNotifications() {
        const btn = $('#btn-enable-browser-notifications').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Enabling…');
        try {
            const updated = await Notifications.enableBrowserNotifications();
            this.currentAgent = { ...this.currentAgent, ...updated, browser_notifications_enabled: true };
            SettingsView.currentAgent = this.currentAgent;
            App.toast('Browser notifications enabled');
        } catch (e) {
            App.toast(e.message, 'error');
        } finally {
            btn.prop('disabled', false).html('<i class="bi bi-bell me-1"></i>Enable');
            this.refreshBrowserNotificationUi();
        }
    },

    async disableBrowserNotifications() {
        const btn = $('#btn-disable-browser-notifications').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Disabling…');
        try {
            const updated = await Notifications.disableBrowserNotifications();
            this.currentAgent = { ...this.currentAgent, ...updated, browser_notifications_enabled: false };
            SettingsView.currentAgent = this.currentAgent;
            App.toast('Browser notifications disabled');
        } catch (e) {
            App.toast(e.message, 'error');
        } finally {
            btn.prop('disabled', false).html('<i class="bi bi-bell-slash me-1"></i>Disable');
            this.refreshBrowserNotificationUi();
        }
    },

    testBrowserNotifications() {
        try {
            Notifications.sendTestBrowserNotification();
            App.toast('Test notification sent', 'success');
        } catch (e) {
            App.toast(e.message, 'error');
        }
    },

    async testSmtp() {
        const btn = $('.btn-test-smtp').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Testing…');
        try {
            const res = await API.post('/admin/settings/test-smtp', {});
            App.toast(res.message || 'Test email sent', 'success');
        } catch (e) {
            App.toast(e.message || 'SMTP test failed', 'error');
        } finally {
            btn.prop('disabled', false).html('<i class="bi bi-envelope me-1"></i>Test SMTP');
        }
    },

    async generatePushKeys() {
        const btn = $('#btn-generate-push-keys');
        const original = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Generating…');
        try {
            const res = await API.post('/admin/settings/generate-push-keys', {});
            const data = res.data || {};
            this.settings.push_vapid_public_key = data.push_vapid_public_key || '';
            this.settings.push_vapid_private_key = '***';
            this.settings.push_vapid_subject = data.push_vapid_subject || this.settings.push_vapid_subject || '';
            this.pushStatus = data.status || (await API.get('/admin/settings/push-status')).data || {};
            this.renderTab('notifications');
            App.toast('VAPID keys generated', 'success');
        } catch (e) {
            App.toast(e.message || 'Failed to generate VAPID keys', 'error');
        } finally {
            btn.prop('disabled', false).html(original);
        }
    },

    async sendTestPush(selector = '#btn-admin-test-push') {
        const btn = $(selector);
        const original = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Sending…');
        try {
            const res = await API.post('/push/test', {});
            App.toast(res.message || 'Test push sent', 'success');
        } catch (e) {
            App.toast(e.message || 'Test push failed', 'error');
        } finally {
            btn.prop('disabled', false).html(original);
        }
    },

    async save(tab) {
        const s = this.settings;
        const tabFields = {
            general:      ['company_name','app_url','timezone','date_format','ticket_prefix','imap_poll_mode','sla_enabled','sla_high_after_days','sla_overdue_after_days','sla_notify_scope'],
            branding:     ['logo_url','favicon_url','primary_color','support_email_display'],
            email:        ['smtp_host','smtp_port','smtp_encryption','smtp_username','smtp_password','smtp_from_address','smtp_from_name','reply_to_address','global_signature','include_portal_link_in_customer_emails','notify_agent_on_new_ticket','notify_agent_on_new_reply'],
            autoresponse: ['auto_response_enabled','auto_response_subject','auto_response_body'],
            imap:         [],
            slack:        ['slack_enabled','slack_webhook_url','slack_channel','slack_on_new_ticket','slack_on_assign','slack_on_new_reply','slack_unfurl_links','slack_username','slack_icon_url','slack_icon_emoji'],
            notifications: ['push_vapid_public_key','push_vapid_private_key','push_vapid_subject','pwa_icon_url'],
            'support-form': ['support_form_recaptcha_site_key','support_form_recaptcha_secret_key','support_form_allowed_origins'],
        };

        const payload = {};
        (tabFields[tab] || []).forEach(key => {
            const el = document.getElementById('s-' + key);
            if (!el) return;
            if (el.type === 'checkbox') {
                payload[key] = el.checked;
            } else if (el.type === 'password') {
                if (el.value) payload[key] = el.value;
            } else {
                payload[key] = key === 'support_form_allowed_origins'
                    ? el.value.split(/\r\n|\r|\n/).map(v => v.trim()).filter(Boolean)
                    : el.value;
            }
        });

        if (tab === 'general') {
            payload.sla_notify_agent_ids = $('.sla-agent-check:checked')
                .map((_, el) => parseInt(el.value, 10))
                .get()
                .filter(Boolean);
            payload.sla_high_after_days = Math.max(0, parseInt(payload.sla_high_after_days, 10) || 0);
            payload.sla_overdue_after_days = Math.max(0, parseInt(payload.sla_overdue_after_days, 10) || 0);
            if (payload.sla_notify_scope === 'specific' && payload.sla_notify_agent_ids.length === 0) {
                App.toast('Select at least one SLA reminder recipient', 'error');
                return;
            }
        }

        try {
            await API.put('/admin/settings', { settings: payload });
            // Update local cache
            Object.assign(this.settings, payload);
            // Clear password fields
            document.querySelectorAll('input[type="password"]').forEach(el => el.value = '');
            // Update public settings cache
            if (tab === 'general' || tab === 'branding') {
                Object.assign(App.settings, payload);
                App.applyAppName(App.settings.company_name || App.appName);
                if (payload.favicon_url !== undefined) App.applyFavicon(payload.favicon_url);
            }
            if (tab === 'notifications') {
                this.pushStatus = (await API.get('/admin/settings/push-status')).data || {};
                if (payload.push_vapid_public_key !== undefined) {
                    App.settings.push_vapid_public_key = payload.push_vapid_public_key;
                }
                if (payload.pwa_icon_url !== undefined) {
                    App.settings.pwa_icon_url = payload.pwa_icon_url;
                    App.applyManifest();
                }
                this.renderTab('notifications');
            }
            App.toast('Settings saved');
        } catch (e) {
            App.toast(e.message, 'error');
        }
    },

    renderSlaRecipients() {
        const selectedIds = new Set((this.settings.sla_notify_agent_ids || []).map(id => String(id)));
        const agents = this.agents.filter(agent => agent.is_active !== 0);
        const content = agents.length
            ? agents.map(agent => `
                <label class="list-group-item d-flex align-items-center gap-2">
                    <input class="form-check-input m-0 sla-agent-check" type="checkbox" value="${agent.id}" ${selectedIds.has(String(agent.id)) ? 'checked' : ''}>
                    <span class="fw-medium">${App.escapeHtml(agent.name)}</span>
                    <span class="text-muted small ms-auto">${App.escapeHtml(agent.email || '')}</span>
                </label>
            `).join('')
            : '<div class="text-muted small">No active agents available.</div>';

        return `
        <div class="card border-0 shadow-sm mt-3" id="sla-recipient-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                    <div>
                        <h6 class="mb-1">SLA Reminder Targets</h6>
                        <p class="text-muted small mb-0">Used for both the high-priority reminder and the overdue reminder when you choose specific agents.</p>
                    </div>
                    <span class="badge text-bg-light border">General</span>
                </div>
                <div class="list-group mt-3" id="sla-recipient-list">${content}</div>
            </div>
        </div>`;
    },

    syncSlaRecipientVisibility() {
        const isSpecific = $('#s-sla_notify_scope').val() === 'specific';
        $('#sla-recipient-card').toggleClass('d-none', !isSpecific);
    }
};

const MyProfileView = {
    render(params) {
        const section = this.resolveSection(params);
        return `
        <div class="container-fluid terminal-screen terminal-screen-settings p-4 terminal-compact">
            <h4 class="terminal-heading mb-3"><i class="bi bi-person-lines-fill me-2"></i>My Profile</h4>
            <div class="terminal-profile-nav mb-3">
                <a class="btn btn-sm ${section === 'profile' ? 'btn-primary' : 'btn-outline-secondary'}" href="#/my-profile">
                    <i class="bi bi-person-lines-fill me-1"></i>Profile
                </a>
                <a class="btn btn-sm ${section === 'alerts' ? 'btn-primary' : 'btn-outline-secondary'}" href="#/my-profile/notifications">
                    <i class="bi bi-layout-text-window-reverse me-1"></i>Alerts Panel
                </a>
                <a class="btn btn-sm ${section === 'notification-settings' ? 'btn-primary' : 'btn-outline-secondary'}" href="#/my-profile/settings/notifications">
                    <i class="bi bi-sliders me-1"></i>Notification Settings
                </a>
            </div>
            <div id="settings-content">
                <div class="text-center py-5 text-muted">
                    <div class="spinner-border"></div><p class="mt-2">Loading…</p>
                </div>
            </div>
        </div>`;
    },

    resolveSection(params) {
        if (params && params.setting === 'notifications') return 'notification-settings';
        if (params && params.section === 'settings' && params.setting === 'notifications') return 'notification-settings';
        if (params && params.section === 'notifications') return 'alerts';
        return 'profile';
    },

    async init(params) {
        try {
            const results = await Promise.all([
                API.get('/auth/me'),
                API.get('/settings/public')
            ]);
            SettingsView.currentAgent = (results[0].data && results[0].data.user) || {};
            SettingsView.publicSettings = results[1].data || {};
            SettingsView.settings = results[1].data || {};
            const section = this.resolveSection(params);
            if (section === 'alerts') {
                await this.renderAlertsPanel();
            } else if (section === 'notification-settings') {
                await this.renderNotificationSettings();
            } else {
                $('#settings-content').html(SettingsView.renderProfilePanel());
                SettingsView.bindProfileSave();
                RichEditor.init('profile-signature', { value: (SettingsView.currentAgent || {}).signature || '' });
            }
        } catch (e) {
            $('#settings-content').html('<div class="alert alert-danger">' + App.escapeHtml(e.message) + '</div>');
        }
    },

    async renderAlertsPanel() {
        $('#settings-content').html(`
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-4">
                        <div>
                            <h6 class="mb-1">Alerts Panel</h6>
                            <div class="text-muted small">Current alerts stay here until the ticket is opened or the underlying overdue condition is cleared.</div>
                        </div>
                        <a class="btn btn-sm btn-outline-secondary" href="#/my-profile/settings/notifications">
                            <i class="bi bi-sliders me-1"></i>Notification Settings
                        </a>
                    </div>
                    <div id="my-profile-notification-summary" class="terminal-notification-overview-summary mb-3"></div>
                    <div id="my-profile-notification-list">
                        <div class="text-center py-5 text-muted">
                            <div class="spinner-border"></div><p class="mt-2">Loading…</p>
                        </div>
                    </div>
                </div>
            </div>
        `);

        this.bindAlertPanelActions();
        await this.loadAlertsPanel();
    },

    browserNotificationOverviewState() {
        const agent = SettingsView.currentAgent || {};
        const supported = 'Notification' in window && 'serviceWorker' in navigator && 'PushManager' in window;
        const permission = ('Notification' in window) ? Notification.permission : 'unsupported';
        const enabled = !!agent.browser_notifications_enabled;

        if (!supported) {
            return {
                key: 'unsupported',
                status: 'Not supported',
                message: 'This browser does not support Web Push notifications.',
            };
        }

        if (permission === 'denied') {
            return {
                key: 'blocked',
                status: 'Blocked in browser',
                message: 'Notifications are blocked for this site. Re-enable them in your browser or site settings to subscribe again.',
            };
        }

        if (permission === 'granted' && enabled) {
            return {
                key: 'subscribed',
                status: 'Subscribed',
                message: 'Browser notifications are enabled for this account.',
            };
        }

        if (permission === 'granted') {
            return {
                key: 'enable',
                status: 'Permission granted',
                message: 'This browser allows notifications, but they are not enabled for this account yet.',
            };
        }

        return {
                key: 'subscribe',
                status: 'Not subscribed',
                message: 'Allow notifications in this browser to receive alerts even when the helpdesk is not open.',
        };
    },

    renderBrowserNotificationOverviewControls() {
        const state = this.browserNotificationOverviewState();
        const $container = $('#my-profile-browser-notification-controls');
        if (!$container.length) return;

        let buttons = '';
        if (state.key === 'subscribed') {
            buttons = `
                <button class="btn btn-outline-secondary btn-sm" id="btn-disable-browser-notifications-overview">
                    <i class="bi bi-bell-slash me-1"></i>Disable
                </button>
                <button class="btn btn-outline-secondary btn-sm" id="btn-test-browser-notifications-overview">
                    <i class="bi bi-broadcast me-1"></i>Send Test
                </button>
                <button class="btn btn-outline-primary btn-sm" id="btn-test-push-notifications-overview">
                    <i class="bi bi-send me-1"></i>Send Push Test
                </button>
            `;
        } else if (state.key === 'enable') {
            buttons = `
                <button class="btn btn-outline-primary btn-sm" id="btn-enable-browser-notifications-overview">
                    <i class="bi bi-bell me-1"></i>Enable Browser Notifications
                </button>
            `;
        } else if (state.key === 'subscribe') {
            buttons = `
                <button class="btn btn-outline-primary btn-sm" id="btn-subscribe-browser-notifications">
                    <i class="bi bi-bell me-1"></i>Subscribe In Your Browser
                </button>
            `;
        }

        $container.html(`
            <div class="text-end">
                <div class="small fw-semibold">${App.escapeHtml(state.status)}</div>
                <div class="text-muted small">${App.escapeHtml(state.message)}</div>
            </div>
            ${buttons}
        `);
    },

    async renderNotificationSettings() {
        let prefs = {};
        try {
            const res = await API.get('/notifications/preferences');
            prefs = (res.data && res.data.preferences) || {};
            SettingsView.currentAgent.browser_notifications_enabled = !!(res.data && res.data.browser_notifications_enabled);
        } catch (e) {
            $('#settings-content').html('<div class="alert alert-danger">' + App.escapeHtml(e.message) + '</div>');
            return;
        }

        const rows = [
            ['update_available', 'Update available', 'Only shown to admins.'],
            ['ticket_created', 'New ticket', 'Cleared when the ticket is opened.'],
            ['ticket_assigned', 'A ticket has been assigned to me', 'Cleared when the ticket is opened.'],
            ['customer_reply', 'A ticket has received a reply', 'Cleared when the ticket is opened.'],
            ['ticket_internal_note', 'A ticket has a new internal note', 'Cleared when the ticket is opened.'],
            ['ticket_sla_overdue', 'A ticket is SLA Overdue', 'Cleared when priority moves away from overdue.'],
            ['ticket_due_overdue', 'A ticket has a due date today or in the past', 'Cleared when the due date changes or priority moves away from overdue.'],
        ].filter(([key]) => key !== 'update_available' || API.isAdmin());

        $('#settings-content').html(`
            <div class="row g-3">
                <div class="col-lg-7">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <h6 class="mb-3">Notification Preferences</h6>
                            <div class="list-group mb-3">
                                ${rows.map(([key, label, hint]) => `
                                    <label class="list-group-item d-flex gap-3 align-items-start">
                                        <input class="form-check-input mt-1 notification-pref-check" type="checkbox" value="${key}" ${prefs[key] ? 'checked' : ''}>
                                        <span>
                                            <span class="d-block fw-semibold">${App.escapeHtml(label)}</span>
                                            <span class="d-block text-muted small">${App.escapeHtml(hint)}</span>
                                        </span>
                                    </label>
                                `).join('')}
                            </div>
                            <button class="btn btn-primary" id="btn-save-notification-preferences">
                                <i class="bi bi-save me-1"></i>Save Notification Preferences
                            </button>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <h6 class="mb-3">Browser Notifications</h6>
                            <div id="my-profile-browser-notification-controls" class="d-flex align-items-center justify-content-between gap-2 flex-wrap"></div>
                        </div>
                    </div>
                    <div class="card border-0 shadow-sm mt-3">
                        <div class="card-body">
                            <h6 class="mb-2">Install Andrea Helpdesk</h6>
                            <p class="text-muted small mb-3">Install the helpdesk as an app for faster access. On iPhone/iPad, open Safari, tap Share, then choose <strong>Add to Home Screen</strong>; iOS push notifications require the installed web app.</p>
                            <div id="my-profile-install-controls" class="d-flex align-items-center justify-content-between gap-2 flex-wrap"></div>
                        </div>
                    </div>
                </div>
            </div>
        `);

        this.bindNotificationSettingsActions();
        this.renderBrowserNotificationOverviewControls();
        this.renderInstallControls();
    },

    bindNotificationSettingsActions() {
        $('#settings-content')
            .off('.profileNotifications')
            .on('click.profileNotifications', '#btn-save-notification-preferences', async () => {
                const btn = $('#btn-save-notification-preferences');
                const original = btn.html();
                const preferences = {};
                $('.notification-pref-check').each(function() {
                    preferences[$(this).val()] = $(this).is(':checked');
                });
                btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Saving…');
                try {
                    await API.put('/notifications/preferences', { preferences });
                    await Notifications.refreshSummary({ silent: true });
                    App.toast('Notification preferences saved', 'success');
                } catch (e) {
                    App.toast(e.message, 'error');
                } finally {
                    btn.prop('disabled', false).html(original);
                }
            })
            .on('click.profileNotifications', '#btn-subscribe-browser-notifications', async () => {
                const btn = $('#btn-subscribe-browser-notifications');
                const original = btn.html();
                btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Subscribing…');
                try {
                    const updated = await Notifications.enableBrowserNotifications();
                    SettingsView.currentAgent = { ...SettingsView.currentAgent, ...updated, browser_notifications_enabled: true };
                    App.toast('Browser notifications enabled');
                } catch (e) {
                    App.toast(e.message, 'error');
                } finally {
                    btn.prop('disabled', false).html(original);
                    this.renderBrowserNotificationOverviewControls();
                }
            })
            .on('click.profileNotifications', '#btn-enable-browser-notifications-overview', async () => {
                const btn = $('#btn-enable-browser-notifications-overview');
                const original = btn.html();
                btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Enabling…');
                try {
                    const updated = await Notifications.enableBrowserNotifications();
                    SettingsView.currentAgent = { ...SettingsView.currentAgent, ...updated, browser_notifications_enabled: true };
                    App.toast('Browser notifications enabled');
                } catch (e) {
                    App.toast(e.message, 'error');
                } finally {
                    btn.prop('disabled', false).html(original);
                    this.renderBrowserNotificationOverviewControls();
                }
            })
            .on('click.profileNotifications', '#btn-disable-browser-notifications-overview', async () => {
                const btn = $('#btn-disable-browser-notifications-overview');
                const original = btn.html();
                btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Disabling…');
                try {
                    const updated = await Notifications.disableBrowserNotifications();
                    SettingsView.currentAgent = { ...SettingsView.currentAgent, ...updated, browser_notifications_enabled: false };
                    App.toast('Browser notifications disabled');
                } catch (e) {
                    App.toast(e.message, 'error');
                } finally {
                    btn.prop('disabled', false).html(original);
                    this.renderBrowserNotificationOverviewControls();
                }
            })
            .on('click.profileNotifications', '#btn-test-browser-notifications-overview', () => {
                try {
                    Notifications.sendTestBrowserNotification();
                    App.toast('Test notification sent', 'success');
                } catch (e) {
                    App.toast(e.message, 'error');
                }
            })
            .on('click.profileNotifications', '#btn-test-push-notifications-overview', async () => {
                const btn = $('#btn-test-push-notifications-overview');
                const original = btn.html();
                btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Sending…');
                try {
                    const res = await API.post('/push/test', {});
                    App.toast(res.message || 'Test push sent', 'success');
                } catch (e) {
                    App.toast(e.message || 'Test push failed', 'error');
                } finally {
                    btn.prop('disabled', false).html(original);
                }
            })
            .on('click.profileNotifications', '#btn-install-pwa', async () => {
                const prompt = App.deferredInstallPrompt;
                if (!prompt) {
                    App.toast('Use your browser menu to install this app on this device', 'info');
                    return;
                }
                prompt.prompt();
                await prompt.userChoice.catch(() => null);
                App.deferredInstallPrompt = null;
                this.renderInstallControls();
            });
    },

    renderInstallControls() {
        const $container = $('#my-profile-install-controls');
        if (!$container.length) return;

        const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
        if (standalone) {
            $container.html(`
                <div>
                    <div class="small fw-semibold">Installed</div>
                    <div class="text-muted small">This browser is running Andrea Helpdesk as an installed app.</div>
                </div>
            `);
            return;
        }

        const canPrompt = !!App.deferredInstallPrompt;
        $container.html(`
            <div>
                <div class="small fw-semibold">${canPrompt ? 'Ready to install' : 'Install from browser menu'}</div>
                <div class="text-muted small">${canPrompt ? 'This browser can install Andrea Helpdesk now.' : 'If no install button is shown, use your browser menu or iOS Share sheet.'}</div>
            </div>
            <button class="btn btn-outline-primary btn-sm" id="btn-install-pwa" ${canPrompt ? '' : ''}>
                <i class="bi bi-phone me-1"></i>Install App
            </button>
        `);
    },

    bindAlertPanelActions() {
        $('#settings-content')
            .off('.profileNotifications')
            .on('click.profileNotifications', '.profile-notification-link', async (e) => {
                e.preventDefault();
                const link = $(e.currentTarget).data('link') || '';
                if (link) {
                    App.navigate(link);
                }
            });
    },

    async loadAlertsPanel() {
        try {
            const overview = await Notifications.fetchActiveOverview(150);
            const items = overview.items || [];
            $('#my-profile-notification-summary').html(`
                <div class="terminal-notification-overview-chip">
                    <strong>${App.escapeHtml(String(overview.activeCount))}</strong>
                    <span>active</span>
                </div>
            `);

            if (!items.length) {
                $('#my-profile-notification-list').html('<div class="terminal-notification-empty">No active notifications right now.</div>');
                return;
            }

            $('#my-profile-notification-list').html(items.map((item) => {
                return `
                    <article class="terminal-notification-card">
                        <div class="terminal-notification-card-head">
                            <div>
                                <div class="terminal-notification-card-title">${App.escapeHtml(item.title || 'Notification')}</div>
                                <div class="terminal-notification-card-meta">${App.escapeHtml(App.formatDate(item.created_at))}</div>
                            </div>
                        </div>
                        ${item.body ? `<div class="terminal-notification-card-body">${App.escapeHtml(item.body)}</div>` : ''}
                        <div class="terminal-notification-card-actions">
                            <a href="#" class="profile-notification-link" data-id="${item.id}" data-link="${App.escapeHtml(item.link || '')}">
                                Open
                            </a>
                        </div>
                    </article>
                `;
            }).join(''));
        } catch (e) {
            $('#my-profile-notification-list').html('<div class="alert alert-danger">' + App.escapeHtml(e.message) + '</div>');
        }
    }
};

const TagsView = {
    render() {
        return `
        <div class="container-fluid terminal-screen terminal-screen-settings p-4 terminal-compact">
            <h4 class="terminal-heading mb-3"><i class="bi bi-tags me-2"></i>Tags</h4>
            <div id="settings-content">
                <div class="text-center py-5 text-muted">
                    <div class="spinner-border"></div><p class="mt-2">Loading…</p>
                </div>
            </div>
        </div>`;
    },

    async init() {
        $('#settings-content').html(SettingsView.renderTagsPanel());
        await SettingsView.loadTags();
    }
};
