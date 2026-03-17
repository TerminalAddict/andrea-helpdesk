/**
 * Admin: Settings View
 */
const SettingsView = {
    settings: {},

    render() {
        return `
        <div class="container-fluid p-4" style="max-width:900px;">
            <h4 class="mb-4"><i class="bi bi-sliders me-2"></i>Settings</h4>

            <ul class="nav nav-tabs mb-4" id="settings-tabs">
                <li class="nav-item"><button class="nav-link active" data-tab="general">General</button></li>
                <li class="nav-item"><button class="nav-link" data-tab="branding">Branding</button></li>
                <li class="nav-item"><button class="nav-link" data-tab="email">Email / SMTP</button></li>
                <li class="nav-item"><button class="nav-link" data-tab="autoresponse">Auto-Response</button></li>
                <li class="nav-item"><button class="nav-link" data-tab="imap">IMAP Polling</button></li>
                <li class="nav-item"><button class="nav-link" data-tab="slack">Slack</button></li>
            </ul>

            <div id="settings-content">
                <div class="text-center py-5 text-muted">
                    <div class="spinner-border"></div><p class="mt-2">Loading…</p>
                </div>
            </div>
        </div>`;
    },

    async init() {
        try {
            const res = await API.get('/admin/settings');
            this.settings = res.data || {};
            this.renderTab('general');
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
            ]);
        } else if (tab === 'branding') {
            html = this.form('branding', [
                { key: 'logo_url',              label: 'Logo URL',       type: 'text',  value: s.logo_url || '' },
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
            ]);
        } else if (tab === 'autoresponse') {
            html = this.form('autoresponse', [
                { key: 'auto_response_enabled', label: 'Enable Auto-Response',    type: 'checkbox', value: s.auto_response_enabled },
                { key: 'auto_response_subject', label: 'Auto-Response Subject',   type: 'text',     value: s.auto_response_subject || 'Re: {{subject}} [{{ticket_number}}]' },
                { key: 'auto_response_body',    label: 'Auto-Response Body',      type: 'textarea', value: s.auto_response_body || '',
                  hint: 'Placeholders: {{customer_name}}, {{ticket_number}}, {{subject}}, {{app_name}}' },
            ]);
        } else if (tab === 'imap') {
            html = this.form('imap', [
                { key: 'imap_enabled',  label: 'Enable IMAP Polling', type: 'checkbox', value: s.imap_enabled },
                { key: 'imap_host',     label: 'IMAP Host',    type: 'text',     value: s.imap_host || '' },
                { key: 'imap_port',     label: 'IMAP Port',    type: 'number',   value: s.imap_port || '993' },
                { key: 'imap_username', label: 'Username',     type: 'email',    value: s.imap_username || '' },
                { key: 'imap_password', label: 'Password',     type: 'password', value: '', placeholder: 'Leave blank to keep current' },
                { key: 'imap_folder',   label: 'Folder/Mailbox', type: 'text',   value: s.imap_folder || 'INBOX' },
                { key: 'imap_delete_after_import', label: 'Delete email after import', type: 'checkbox', value: s.imap_delete_after_import,
                  hint: 'If unchecked, emails are marked as read instead' },
            ]);
        } else if (tab === 'slack') {
            html = this.form('slack', [
                { key: 'slack_enabled',      label: 'Enable Slack Notifications',  type: 'checkbox', value: s.slack_enabled },
                { key: 'slack_webhook_url',  label: 'Webhook URL',                 type: 'text',     value: s.slack_webhook_url || '' },
                { key: 'slack_channel',      label: 'Channel',                     type: 'text',     value: s.slack_channel || '#helpdesk' },
                { key: 'slack_on_new_ticket', label: 'Notify on new tickets',      type: 'checkbox', value: s.slack_on_new_ticket },
                { key: 'slack_on_assign',    label: 'Notify on ticket assignment', type: 'checkbox', value: s.slack_on_assign },
            ]);
        }

        $('#settings-content').html(html);

        // Bind save
        $('.btn-save-settings').on('click', (e) => {
            this.save($(e.currentTarget).data('tab'));
        });
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

    async save(tab) {
        const s = this.settings;
        const tabFields = {
            general:      ['company_name','app_url','timezone','date_format','ticket_prefix'],
            branding:     ['logo_url','primary_color','support_email_display'],
            email:        ['smtp_host','smtp_port','smtp_encryption','smtp_username','smtp_password','smtp_from_address','smtp_from_name','reply_to_address','global_signature','notify_agent_on_new_ticket'],
            autoresponse: ['auto_response_enabled','auto_response_subject','auto_response_body'],
            imap:         ['imap_enabled','imap_host','imap_port','imap_username','imap_password','imap_folder','imap_delete_after_import'],
            slack:        ['slack_enabled','slack_webhook_url','slack_channel','slack_on_new_ticket','slack_on_assign'],
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
            if (tab === 'general') {
                Object.assign(App.settings, payload);
                if (payload.company_name) App.applyAppName(payload.company_name);
            }
            App.toast('Settings saved');
        } catch (e) {
            App.toast(e.message, 'error');
        }
    }
};
