/**
 * Ticket Detail View
 */
const TicketDetailView = {
    ticket: null,
    agents: [],

    render(params) {
        this._params = params;
        return `
        <div class="container-fluid p-4" id="ticket-detail-wrap">
            <div class="text-center py-5 text-muted">
                <div class="spinner-border"></div><p class="mt-2">Loading ticket...</p>
            </div>
        </div>`;
    },

    async init(params) {
        this._params = params;
        try {
            const [ticketRes, agentsRes] = await Promise.all([
                API.get('/tickets/' + params.id),
                API.get('/agents'),
            ]);
            this.ticket = ticketRes.data;
            this.agents = agentsRes.data || [];
            this.renderFull();
        } catch (e) {
            $('#ticket-detail-wrap').html('<div class="alert alert-danger m-4">' + App.escapeHtml(e.message) + '</div>');
        }
    },

    renderFull() {
        const t = this.ticket;
        const canClose  = API.can('can_close_tickets') || API.isAdmin();
        const canDelete = API.can('can_delete_tickets') || API.isAdmin();

        const html = `
        <div class="container-fluid p-4" id="ticket-detail-wrap">
            <!-- Breadcrumb + actions -->
            <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
                <div>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-1">
                            <li class="breadcrumb-item"><a href="#/tickets">Tickets</a></li>
                            <li class="breadcrumb-item active">${App.escapeHtml(t.ticket_number)}</li>
                        </ol>
                    </nav>
                    <h5 class="mb-0 fw-bold">${App.escapeHtml(t.subject)}</h5>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    ${canClose ? `
                    <button class="btn btn-sm btn-outline-success" id="btn-resolve" ${t.status === 'resolved' ? 'disabled' : ''}>
                        <i class="bi bi-check-circle me-1"></i>Resolve
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" id="btn-close" ${t.status === 'closed' ? 'disabled' : ''}>
                        <i class="bi bi-x-circle me-1"></i>Close
                    </button>` : ''}
                    ${t.status === 'closed' || t.status === 'resolved' ? `
                    <button class="btn btn-sm btn-outline-primary" id="btn-reopen">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Reopen
                    </button>` : ''}
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                            <i class="bi bi-three-dots"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="#" id="btn-spawn-child"><i class="bi bi-diagram-2 me-2"></i>Create Child Ticket</a></li>
                            <li><a class="dropdown-item" href="#" id="btn-merge"><i class="bi bi-git me-2"></i>Merge into…</a></li>
                            <li><a class="dropdown-item" href="#" id="btn-link-related"><i class="bi bi-link-45deg me-2"></i>Link Related Ticket</a></li>
                            <li><a class="dropdown-item" href="#" id="btn-move-kb"><i class="bi bi-book me-2"></i>Move to Knowledge Base</a></li>
                            ${canDelete ? '<li><hr class="dropdown-divider"></li><li><a class="dropdown-item text-danger" href="#" id="btn-delete-ticket"><i class="bi bi-trash me-2"></i>Delete Ticket</a></li>' : ''}
                        </ul>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <!-- LEFT: Thread + Reply -->
                <div class="col-lg-8">
                    <!-- Thread -->
                    <div id="ticket-thread" class="mb-3"></div>

                    <!-- Reply Editor -->
                    <div class="card border-0 shadow-sm" id="reply-card">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
                            <div class="btn-group btn-group-sm" role="group" id="reply-type-group">
                                <input type="radio" class="btn-check" name="replyType" id="rt-reply" value="reply" checked>
                                <label class="btn btn-outline-primary" for="rt-reply"><i class="bi bi-reply me-1"></i>Reply</label>
                                <input type="radio" class="btn-check" name="replyType" id="rt-note" value="note">
                                <label class="btn btn-outline-warning" for="rt-note"><i class="bi bi-sticky me-1"></i>Internal Note</label>
                            </div>
                            <small class="text-muted" id="reply-to-label">To: ${App.escapeHtml(t.customer_email || '')}</small>
                        </div>
                        <div class="card-body">
                            <textarea class="form-control" id="reply-body" rows="6" placeholder="Write your reply…"></textarea>

                            <!-- Attachments -->
                            <div class="mt-2">
                                <label class="btn btn-sm btn-outline-secondary" for="reply-files">
                                    <i class="bi bi-paperclip me-1"></i>Attach Files
                                </label>
                                <input type="file" id="reply-files" multiple class="d-none">
                                <div id="reply-attachments-preview" class="d-flex flex-wrap gap-1 mt-1"></div>
                            </div>
                        </div>
                        <div class="card-footer bg-white d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center gap-2">
                                <select class="form-select form-select-sm" id="reply-status-change" style="width:auto;">
                                    <option value="">Keep status</option>
                                    <option value="open">Set Open</option>
                                    <option value="pending">Set Pending</option>
                                    <option value="resolved">Set Resolved</option>
                                    <option value="closed">Set Closed</option>
                                </select>
                            </div>
                            <button class="btn btn-primary btn-sm" id="btn-send-reply">
                                <span class="spinner-border spinner-border-sm d-none me-1" id="reply-spinner"></span>
                                <i class="bi bi-send me-1"></i>Send Reply
                            </button>
                        </div>
                    </div>
                </div>

                <!-- RIGHT: Sidebar -->
                <div class="col-lg-4">
                    <!-- Ticket Info -->
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-header bg-white fw-semibold py-2">
                            <i class="bi bi-info-circle me-2"></i>Ticket Info
                        </div>
                        <div class="card-body py-2">
                            <div class="mb-2">
                                <label class="form-label small text-muted mb-1">Status</label>
                                <select class="form-select form-select-sm" id="edit-status">
                                    ${['open','pending','resolved','closed'].map(s =>
                                        `<option value="${s}" ${t.status === s ? 'selected' : ''}>${s.charAt(0).toUpperCase()+s.slice(1)}</option>`
                                    ).join('')}
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small text-muted mb-1">Priority</label>
                                <select class="form-select form-select-sm" id="edit-priority">
                                    ${['urgent','high','normal','low'].map(p =>
                                        `<option value="${p}" ${t.priority === p ? 'selected' : ''}>${p.charAt(0).toUpperCase()+p.slice(1)}</option>`
                                    ).join('')}
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small text-muted mb-1">Assigned To</label>
                                <select class="form-select form-select-sm" id="edit-assigned">
                                    <option value="">Unassigned</option>
                                    ${this.agents.map(a =>
                                        `<option value="${a.id}" ${t.assigned_agent_id == a.id ? 'selected' : ''}>${App.escapeHtml(a.name)}</option>`
                                    ).join('')}
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small text-muted mb-1">Channel</label>
                                <div class="small">${App.escapeHtml(t.channel || '–')}</div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small text-muted mb-1">Created</label>
                                <div class="small">${App.formatDate(t.created_at)}</div>
                            </div>
                            <div class="mb-0">
                                <label class="form-label small text-muted mb-1">Updated</label>
                                <div class="small">${App.formatDate(t.updated_at)}</div>
                            </div>
                        </div>
                    </div>

                    <!-- Customer Info -->
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-header bg-white fw-semibold py-2 d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-person me-2"></i>Customer</span>
                            ${t.customer_id ? `<a href="#/customers/${t.customer_id}" class="btn btn-xs btn-outline-secondary btn-sm py-0 px-2" style="font-size:.75rem;">View</a>` : ''}
                        </div>
                        <div class="card-body py-2">
                            <div class="fw-semibold">${App.escapeHtml(t.customer_name || '–')}</div>
                            <div class="small text-muted">${App.escapeHtml(t.customer_email || '')}</div>
                            ${t.customer_phone ? `<div class="small text-muted">${App.escapeHtml(t.customer_phone)}</div>` : ''}
                        </div>
                    </div>

                    <!-- Tags -->
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-header bg-white fw-semibold py-2">
                            <i class="bi bi-tags me-2"></i>Tags
                        </div>
                        <div class="card-body py-2">
                            <div id="tags-display" class="d-flex flex-wrap gap-1 mb-2">
                                ${(t.tags || []).map(tag =>
                                    `<span class="badge bg-secondary">${App.escapeHtml(tag.name)} <a href="#" class="text-white text-decoration-none ms-1 tag-remove" data-id="${tag.id}">×</a></span>`
                                ).join('')}
                            </div>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" id="tag-input" placeholder="Add tag…">
                                <button class="btn btn-outline-secondary" id="btn-add-tag">Add</button>
                            </div>
                        </div>
                    </div>

                    <!-- Attachments -->
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-header bg-white fw-semibold py-2">
                            <i class="bi bi-paperclip me-2"></i>Attachments
                        </div>
                        <div class="card-body py-2" id="ticket-attachments">
                            ${this.renderAttachmentsList(t.attachments || [])}
                        </div>
                    </div>

                    <!-- Participants -->
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-header bg-white fw-semibold py-2">
                            <i class="bi bi-people me-2"></i>Participants (CC)
                        </div>
                        <div class="card-body py-2">
                            <div id="participants-list">
                                ${(t.participants || []).map(p =>
                                    `<div class="small d-flex justify-content-between align-items-center mb-1">
                                        <span>${App.escapeHtml(p.name || p.email)}</span>
                                        <a href="#" class="text-danger small participant-remove" data-email="${App.escapeHtml(p.email)}">×</a>
                                    </div>`
                                ).join('') || '<p class="small text-muted mb-1">No CC participants.</p>'}
                            </div>
                            <div class="input-group input-group-sm mt-2">
                                <input type="email" class="form-control" id="participant-email-input" placeholder="Add email…">
                                <button class="btn btn-outline-secondary" id="btn-add-participant">Add</button>
                            </div>
                        </div>
                    </div>

                    <!-- Related Tickets -->
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-header bg-white fw-semibold py-2">
                            <i class="bi bi-link-45deg me-2"></i>Related Tickets
                        </div>
                        <div class="card-body py-2" id="related-tickets-list">
                            ${this.renderRelatedTickets(t.relations || [], t.children || [], t.parent || null)}
                        </div>
                    </div>
                </div>
            </div>
        </div>`;

        $('#ticket-detail-wrap').replaceWith(html);
        this.renderThread(t.replies || []);
        this.bindEvents();
    },

    renderThread(replies) {
        if (!replies.length) {
            $('#ticket-thread').html('<p class="text-muted small">No messages yet.</p>');
            return;
        }

        const html = replies.map(r => {
            const isSystem   = r.type === 'system';
            const isInternal = r.type === 'internal';
            const isAgent    = r.author_type === 'agent';

            if (isSystem) {
                return `<div class="text-center my-2">
                    <small class="text-muted fst-italic border rounded px-3 py-1 d-inline-block bg-light">
                        ${App.escapeHtml(r.body)}
                    </small>
                </div>`;
            }

            const attachments = (r.attachments || []).map(a =>
                `<span class="d-inline-flex align-items-center me-1 mb-1 border rounded px-2 py-1 bg-white" style="font-size:.8rem;">
                    <a href="/attachment/${a.id}?token=${a.download_token || ''}" target="_blank" class="text-decoration-none text-body me-1">
                        <i class="bi bi-paperclip me-1 text-muted"></i>${App.escapeHtml(a.filename)}
                    </a>
                    <a href="#" class="text-danger attachment-delete ms-1" data-id="${a.id}" title="Delete attachment">
                        <i class="bi bi-x"></i>
                    </a>
                </span>`
            ).join('');

            if (isInternal) {
                return `
                <div class="card shadow-sm mb-3" style="border-left:4px solid #f0ad4e;border-top:1px solid #fde8b5;border-right:1px solid #fde8b5;border-bottom:1px solid #fde8b5;background:#fffdf0;">
                    <div class="card-header d-flex justify-content-between align-items-center py-2" style="background:#fff8dc;border-bottom:1px solid #fde8b5;">
                        <div>
                            <i class="bi bi-lock-fill text-warning me-1"></i>
                            <span class="badge bg-warning text-dark me-2">Internal Note</span>
                            <strong class="small">${App.escapeHtml(r.author_name || 'Agent')}</strong>
                            <span class="text-muted small ms-2">${App.formatDate(r.created_at)}</span>
                        </div>
                        <span class="badge bg-warning text-dark bg-opacity-75 small">Only visible to agents</span>
                    </div>
                    <div class="card-body py-3">
                        <div class="reply-body fst-italic">${this.renderBody(r.body, r.body_html)}</div>
                        ${attachments ? `<div class="mt-2 pt-2 border-top">${attachments}</div>` : ''}
                    </div>
                </div>`;
            }

            return `
            <div class="card border-0 shadow-sm mb-3 ${isAgent ? 'bg-light' : 'bg-white'}">
                <div class="card-header ${isAgent ? 'bg-light' : 'bg-white'} d-flex justify-content-between align-items-center py-2 border-bottom">
                    <div>
                        <strong class="small">${App.escapeHtml(r.author_name || (isAgent ? 'Agent' : 'Customer'))}</strong>
                        <span class="text-muted small ms-2">${App.formatDate(r.created_at)}</span>
                    </div>
                    <span class="badge ${isAgent ? 'bg-primary' : 'bg-success'} bg-opacity-75 small">${isAgent ? 'Agent' : 'Customer'}</span>
                </div>
                <div class="card-body py-3">
                    <div class="reply-body">${this.renderBody(r.body, r.body_html)}</div>
                    ${attachments ? `<div class="mt-2 pt-2 border-top">${attachments}</div>` : ''}
                </div>
            </div>`;
        }).join('');

        $('#ticket-thread').html(html);
    },

    renderBody(text, html) {
        if (html) return `<div class="reply-html">${html}</div>`;
        return '<pre class="mb-0" style="white-space:pre-wrap;font-family:inherit;font-size:.9rem;">' + App.escapeHtml(text || '') + '</pre>';
    },

    renderAttachmentsList(attachments) {
        if (!attachments.length) return '<p class="small text-muted mb-0">No attachments.</p>';
        return attachments.map(a =>
            `<div class="small mb-1 d-flex align-items-center justify-content-between">
                <div>
                    <i class="bi bi-file-earmark me-1 text-muted"></i>
                    <a href="/attachment/${a.id}?token=${a.download_token || ''}" target="_blank">${App.escapeHtml(a.filename)}</a>
                    <span class="text-muted ms-1">(${this.formatBytes(a.size_bytes || 0)})</span>
                </div>
                <a href="#" class="text-danger attachment-delete ms-2" data-id="${a.id}" title="Delete">
                    <i class="bi bi-trash3" style="font-size:.85rem;"></i>
                </a>
            </div>`
        ).join('');
    },

    renderRelatedTickets(relations, children, parent) {
        let html = '';
        if (parent) {
            html += '<div class="small fw-semibold text-muted mb-1">Parent Ticket</div>';
            html += `<div class="small mb-2"><i class="bi bi-arrow-up-circle text-secondary me-1"></i><a href="#/tickets/${parent.id}">${App.escapeHtml(parent.ticket_number)}</a> — ${App.escapeHtml(parent.subject)} ${App.statusBadge(parent.status)}</div>`;
        }
        if (children && children.length) {
            if (html) html += '<hr class="my-1">';
            html += '<div class="small fw-semibold text-muted mb-1">Child Tickets</div>';
            html += children.map(c =>
                `<div class="small mb-1"><i class="bi bi-arrow-down-circle text-secondary me-1"></i><a href="#/tickets/${c.id}">${App.escapeHtml(c.ticket_number)}</a> — ${App.escapeHtml(c.subject)} ${App.statusBadge(c.status)}</div>`
            ).join('');
        }
        if (relations && relations.length) {
            if (html) html += '<hr class="my-2">';
            html += '<div class="small fw-semibold text-muted mb-1">Related</div>';
            html += relations.map(r =>
                `<div class="small mb-1 d-flex justify-content-between align-items-center">
                    <span><a href="#/tickets/${r.id}">${App.escapeHtml(r.ticket_number)}</a> — ${App.escapeHtml(r.subject)}</span>
                    <a href="#" class="text-danger relation-remove ms-2" data-id="${r.id}" title="Remove link">×</a>
                </div>`
            ).join('');
        }
        return html || '<p class="small text-muted mb-0">None.</p>';
    },

    formatBytes(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes/1024).toFixed(1) + ' KB';
        return (bytes/1048576).toFixed(1) + ' MB';
    },

    bindEvents() {
        const ticketId = this.ticket.id;

        // Inline field changes
        $('#edit-priority').on('change', async () => {
            try {
                await API.put('/tickets/' + ticketId, { priority: $('#edit-priority').val() });
                App.toast('Priority updated');
                await this.reload();
            } catch (e) { App.toast(e.message, 'error'); }
        });

        $('#edit-assigned').on('change', async () => {
            const val = $('#edit-assigned').val();
            try {
                await API.put('/tickets/' + ticketId, { assigned_agent_id: val || null });
                App.toast('Assignment updated');
                await this.reload();
            } catch (e) { App.toast(e.message, 'error'); }
        });

        $('#edit-status').on('change', async () => {
            try {
                await this.setStatus($('#edit-status').val());
            } catch (e) { App.toast(e.message, 'error'); }
        });

        // Status buttons
        $('#btn-resolve').on('click', () => this.setStatus('resolved'));
        $('#btn-close').on('click',   () => this.setStatus('closed'));
        $('#btn-reopen').on('click',  () => this.setStatus('open'));

        // Reply type toggle
        $('input[name="replyType"]').on('change', function() {
            const isNote = $(this).val() === 'note';
            $('#reply-to-label').toggle(!isNote);
            $('#reply-status-change').closest('.d-flex').toggle(!isNote);
            $('#btn-send-reply').html(isNote
                ? '<i class="bi bi-sticky me-1"></i>Add Note'
                : '<i class="bi bi-send me-1"></i>Send Reply');
        });

        // Attachment preview
        $('#reply-files').on('change', function() {
            const files = Array.from(this.files);
            const preview = files.map(f =>
                `<span class="badge bg-light text-dark border">${App.escapeHtml(f.name)}</span>`
            ).join('');
            $('#reply-attachments-preview').html(preview);
        });

        // Send reply
        $('#btn-send-reply').on('click', () => this.sendReply());

        // Tags
        $('#btn-add-tag').on('click', () => this.addTag());
        $('#tag-input').on('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); this.addTag(); } });
        $(document).on('click', '.tag-remove', (e) => {
            e.preventDefault();
            this.removeTag($(e.currentTarget).data('id'));
        });

        // Participants
        $('#btn-add-participant').on('click', () => this.addParticipant());
        $('#participant-email-input').on('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); this.addParticipant(); }
        });
        $(document).on('click', '.participant-remove', (e) => {
            e.preventDefault();
            this.removeParticipant($(e.currentTarget).data('email'));
        });

        // Dropdown actions
        $('#btn-spawn-child').on('click', (e) => { e.preventDefault(); this.spawnChild(); });
        $('#btn-merge').on('click',       (e) => { e.preventDefault(); this.showMergeModal(); });
        $('#btn-link-related').on('click',(e) => { e.preventDefault(); this.showLinkModal(); });
        $('#btn-move-kb').on('click',     (e) => { e.preventDefault(); this.moveToKb(); });
        $('#btn-delete-ticket').on('click',(e) => { e.preventDefault(); this.deleteTicket(); });

        // Relation remove
        $(document).on('click', '.relation-remove', (e) => {
            e.preventDefault();
            this.removeRelation($(e.currentTarget).data('id'));
        });

        // Attachment delete
        $(document).on('click', '.attachment-delete', (e) => {
            e.preventDefault();
            this.deleteAttachment($(e.currentTarget).data('id'));
        });
    },

    async sendReply() {
        const type   = $('input[name="replyType"]:checked').val();
        const body   = $('#reply-body').val().trim();
        const status = $('#reply-status-change').val();
        const files  = document.getElementById('reply-files').files;

        if (!body) { App.toast('Reply body is required', 'warning'); return; }

        $('#reply-spinner').removeClass('d-none');
        $('#btn-send-reply').prop('disabled', true);

        try {
            // Send body + files together so the email includes the attachments
            const fd = new FormData();
            fd.append('body', body);
            fd.append('type', type === 'note' ? 'internal' : 'reply');
            if (status) fd.append('status_after', status);
            for (const file of files) fd.append('file[]', file);

            await API.upload('/tickets/' + this.ticket.id + '/replies', fd);

            $('#reply-body').val('');
            $('#reply-files').val('');
            $('#reply-attachments-preview').empty();
            App.toast(type === 'note' ? 'Note added' : 'Reply sent');
            await this.reload();
        } catch (e) {
            App.toast(e.message, 'error');
        } finally {
            $('#reply-spinner').addClass('d-none');
            $('#btn-send-reply').prop('disabled', false);
        }
    },

    async setStatus(status) {
        try {
            await API.post('/tickets/' + this.ticket.id + '/status', { status });
            App.toast('Status updated to ' + status);
            await this.reload();
        } catch (e) { App.toast(e.message, 'error'); }
    },

    async addTag() {
        const name = $('#tag-input').val().trim();
        if (!name) return;
        try {
            await API.post('/tickets/' + this.ticket.id + '/tags', { name });
            $('#tag-input').val('');
            await this.reload();
        } catch (e) { App.toast(e.message, 'error'); }
    },

    async removeTag(tagId) {
        try {
            await API.delete('/tickets/' + this.ticket.id + '/tags/' + tagId);
            await this.reload();
        } catch (e) { App.toast(e.message, 'error'); }
    },

    async addParticipant() {
        const email = $('#participant-email-input').val().trim();
        if (!email) return;
        try {
            await API.post('/tickets/' + this.ticket.id + '/participants', { email });
            $('#participant-email-input').val('');
            await this.reload();
        } catch (e) { App.toast(e.message, 'error'); }
    },

    async removeParticipant(email) {
        try {
            await API.delete('/tickets/' + this.ticket.id + '/participants', { email });
            await this.reload();
        } catch (e) { App.toast(e.message, 'error'); }
    },

    async deleteAttachment(id) {
        if (!confirm('Delete this attachment?')) return;
        try {
            await API.delete('/attachments/' + id);
            await this.reload();
        } catch (e) { App.toast(e.message, 'error'); }
    },

    async removeRelation(id) {
        try {
            await API.delete('/tickets/' + this.ticket.id + '/relations/' + id);
            await this.reload();
        } catch (e) { App.toast(e.message, 'error'); }
    },

    async spawnChild() {
        App.navigate('/tickets/new?parent_id=' + this.ticket.id);
    },

    async showMergeModal() {
        const target = prompt('Enter ticket number to merge this ticket INTO:');
        if (!target) return;
        try {
            const res = await API.get('/tickets', { q: target, per_page: 5 });
            const match = (res.data || []).find(t => t.ticket_number === target.trim());
            if (!match) { App.toast('Ticket not found: ' + target, 'error'); return; }
            const confirmed = await App.confirm(`Merge ${this.ticket.ticket_number} into ${match.ticket_number}? This cannot be undone.`, 'Confirm Merge');
            if (!confirmed) return;
            await API.post('/tickets/' + this.ticket.id + '/merge', { target_ticket_id: match.id });
            App.toast('Ticket merged');
            App.navigate('/tickets/' + match.id);
        } catch (e) { App.toast(e.message, 'error'); }
    },

    async showLinkModal() {
        const target = prompt('Enter ticket number to link as related:');
        if (!target) return;
        try {
            const res = await API.get('/tickets', { q: target, per_page: 5 });
            const match = (res.data || []).find(t => t.ticket_number === target.trim());
            if (!match) { App.toast('Ticket not found: ' + target, 'error'); return; }
            await API.post('/tickets/' + this.ticket.id + '/relations', { related_ticket_id: match.id, relation_type: 'related' });
            App.toast('Ticket linked');
            await this.reload();
        } catch (e) { App.toast(e.message, 'error'); }
    },

    async moveToKb() {
        const confirmed = await App.confirm('Move this ticket to the Knowledge Base? A new KB article will be created from the ticket content.', 'Move to KB');
        if (!confirmed) return;
        try {
            const res = await API.post('/tickets/' + this.ticket.id + '/move-to-kb');
            App.toast('Draft KB article created — review and publish it in the Knowledge Base');
            App.navigate('/kb?edit=' + res.data.id);
        } catch (e) { App.toast(e.message, 'error'); }
    },

    async deleteTicket() {
        const confirmed = await App.confirm('Permanently delete this ticket? This cannot be undone.', 'Delete Ticket');
        if (!confirmed) return;
        try {
            await API.delete('/tickets/' + this.ticket.id);
            App.toast('Ticket deleted');
            App.navigate('/tickets');
        } catch (e) { App.toast(e.message, 'error'); }
    },

    async reload() {
        try {
            const res = await API.get('/tickets/' + this.ticket.id);
            this.ticket = res.data;
            this.renderFull();
        } catch (e) {}
    },
};
