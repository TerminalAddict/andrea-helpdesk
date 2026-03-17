/**
 * Admin: Settings View
 */
const SettingsView = {
    settings: {},

    render() {
        const isAdmin   = API.isAdmin();
        const adminTabs = isAdmin ? `
                <li class="nav-item"><button class="nav-link" data-tab="general">General</button></li>
                <li class="nav-item"><button class="nav-link" data-tab="branding">Branding</button></li>
                <li class="nav-item"><button class="nav-link" data-tab="email">Email / SMTP</button></li>
                <li class="nav-item"><button class="nav-link" data-tab="autoresponse">Auto-Response</button></li>
                <li class="nav-item"><button class="nav-link" data-tab="imap">IMAP Polling</button></li>
                <li class="nav-item"><button class="nav-link" data-tab="slack">Slack</button></li>` : '';
        const tagTab    = (isAdmin || API.can('can_manage_tags')) ? `
                <li class="nav-item"><button class="nav-link" data-tab="tags">Tags</button></li>` : '';
        const profileTab = `<li class="nav-item"><button class="nav-link" data-tab="profile">My Profile</button></li>`;

        return `
        <div class="container-fluid p-4" style="max-width:900px;">
            <h4 class="mb-4"><i class="bi bi-sliders me-2"></i>Settings</h4>

            <ul class="nav nav-tabs mb-4" id="settings-tabs">
                ${adminTabs}
                ${tagTab}
                ${profileTab}
            </ul>

            <div id="settings-content">
                <div class="text-center py-5 text-muted">
                    <div class="spinner-border"></div><p class="mt-2">Loading…</p>
                </div>
            </div>
        </div>`;
    },

    async init() {
        const isAdmin  = API.isAdmin();
        const canTags  = isAdmin || API.can('can_manage_tags');
        const firstTab = isAdmin ? 'general' : (canTags ? 'tags' : 'profile');
        try {
            const fetches = [API.get('/auth/me'), API.get('/settings/public')];
            if (isAdmin) fetches.push(API.get('/admin/settings'));
            const results = await Promise.all(fetches);
            this.currentAgent = (results[0].data && results[0].data.user) || {};
            // Non-admins get global_signature from public settings; admins get full settings
            if (isAdmin) {
                this.settings = results[2].data || {};
            } else {
                this.settings = results[1].data || {};
            }
            this.renderTab(firstTab);
            this.bindTabSwitching();
        } catch (e) {
            $('#settings-content').html('<div class="alert alert-danger">' + App.escapeHtml(e.message) + '</div>');
        }
    },

    bindTabSwitching() {
        $('#settings-tabs button').on('click', (e) => {
            $('#settings-tabs button').removeClass('active');
            $(e.currentTarget).addClass('active');
            this.renderTab($(e.currentTarget).data('tab'));
        });
    },

    renderTab(tab) {
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
            ]);
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
                { key: 'smtp_username',   label: 'SMTP Username',   type: 'email',    value: s.smtp_username || '' },
                { key: 'smtp_password',   label: 'SMTP Password',   type: 'password', value: '', placeholder: 'Leave blank to keep current' },
                { key: 'smtp_from_address', label: 'From Email',    type: 'email',    value: s.smtp_from_address || '' },
                { key: 'smtp_from_name',  label: 'From Name',       type: 'text',     value: s.smtp_from_name || '' },
                { key: 'reply_to_address', label: 'Reply-To Email', type: 'email',    value: s.reply_to_address || '', hint: 'Replies to this address create/update tickets' },
                { key: 'global_signature', label: 'Email Signature', type: 'textarea', value: s.global_signature || '', hint: 'Use {{agent_name}} as placeholder' },
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
            html = this.form('slack', [
                { key: 'slack_enabled',      label: 'Enable Slack Notifications',  type: 'checkbox', value: s.slack_enabled },
                { key: 'slack_webhook_url',  label: 'Webhook URL',                 type: 'text',     value: s.slack_webhook_url || '' },
                { key: 'slack_channel',      label: 'Channel',                     type: 'text',     value: s.slack_channel || '#helpdesk' },
                { key: 'slack_on_new_ticket', label: 'Notify on new tickets',           type: 'checkbox', value: s.slack_on_new_ticket },
                { key: 'slack_on_assign',    label: 'Notify on ticket assignment',      type: 'checkbox', value: s.slack_on_assign },
                { key: 'slack_on_new_reply', label: 'Notify on new customer reply',     type: 'checkbox', value: s.slack_on_new_reply },
            ]);
        }

        if (tab === 'tags') {
            $('#settings-content').html(this.renderTagsPanel());
            this.loadTags();
            return;
        }

        if (tab === 'profile') {
            $('#settings-content').html(this.renderProfilePanel());
            this.bindProfileSave();
            return;
        }

        $('#settings-content').html(html);

        // Bind save
        $('.btn-save-settings').on('click', (e) => {
            this.save($(e.currentTarget).data('tab'));
        });

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
                        <code class="d-block bg-dark text-light rounded p-2 small user-select-all">${App.escapeHtml(cronCmd)}</code>
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
            } else {
                input = `<input type="${f.type}" class="form-control" id="s-${f.key}" value="${App.escapeHtml(f.value || '')}"${f.placeholder ? ` placeholder="${App.escapeHtml(f.placeholder)}"` : ''}>`;
            }
            return `<div class="mb-3">
                <label class="form-label" for="s-${f.key}">${App.escapeHtml(f.label)}</label>
                ${input}
                ${f.hint ? `<div class="form-text">${App.escapeHtml(f.hint)}</div>` : ''}
            </div>`;
        }).join('');

        return `
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                ${inputs}
                <div class="mt-3">
                    <button class="btn btn-primary btn-save-settings" data-tab="${tab}">
                        <i class="bi bi-save me-1"></i>Save Settings
                    </button>
                </div>
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
                        <input type="hidden" id="imap-account-id">
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
                            <input type="email" class="form-control" id="imap-username">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">From Address <span class="text-muted small">(for outgoing emails)</span></label>
                            <input type="email" class="form-control" id="imap-from-address" placeholder="Leave blank to use Username">
                            <div class="form-text">Tickets tagged by this account will be sent from this address.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="imap-password" placeholder="Leave blank to keep current when editing">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Folder / Mailbox</label>
                            <input type="text" class="form-control" id="imap-folder" value="INBOX">
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
                    const pollStr = a.last_poll_at
                        ? `Last poll: ${App.formatDate(a.last_poll_at)} · ${a.last_poll_count} message(s) imported`
                        : 'No polls recorded yet';
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
        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('imapAccountModal'));

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
            bootstrap.Modal.getInstance(document.getElementById('imapAccountModal')).hide();
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
        const globalSig     = this.settings.global_signature || '';
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

                <button class="btn btn-primary" id="btn-save-profile">
                    <i class="bi bi-save me-1"></i>Save Profile
                </button>
            </div>
        </div>`;
    },

    bindProfileSave() {
        $('#btn-save-profile').on('click', () => this.saveProfile());
    },

    async saveProfile() {
        const signature       = $('#profile-signature').val();
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

    async save(tab) {
        const s = this.settings;
        const tabFields = {
            general:      ['company_name','app_url','timezone','date_format','ticket_prefix','imap_poll_mode'],
            branding:     ['logo_url','favicon_url','primary_color','support_email_display'],
            email:        ['smtp_host','smtp_port','smtp_encryption','smtp_username','smtp_password','smtp_from_address','smtp_from_name','reply_to_address','global_signature','notify_agent_on_new_ticket','notify_agent_on_new_reply'],
            autoresponse: ['auto_response_enabled','auto_response_subject','auto_response_body'],
            imap:         [],
            slack:        ['slack_enabled','slack_webhook_url','slack_channel','slack_on_new_ticket','slack_on_assign','slack_on_new_reply'],
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
                payload[key] = el.value;
            }
        });

        try {
            await API.put('/admin/settings', { settings: payload });
            // Update local cache
            Object.assign(this.settings, payload);
            // Clear password fields
            document.querySelectorAll('input[type="password"]').forEach(el => el.value = '');
            // Update public settings cache
            if (tab === 'general' || tab === 'branding') {
                Object.assign(App.settings, payload);
                App.applyAppName(App.appName);
                if (payload.favicon_url !== undefined) App.applyFavicon(payload.favicon_url);
            }
            App.toast('Settings saved');
        } catch (e) {
            App.toast(e.message, 'error');
        }
    }
};
