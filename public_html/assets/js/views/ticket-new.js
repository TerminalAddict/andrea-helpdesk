/**
 * New Ticket View (create on behalf of customer / phone channel)
 */
const TicketNewView = {
    render(params) {
        this._params = params || {};
        return `
        <div class="container-fluid p-4" style="max-width:800px;">
            <nav aria-label="breadcrumb" class="mb-3">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="#/tickets">Tickets</a></li>
                    <li class="breadcrumb-item active">New Ticket</li>
                </ol>
            </nav>
            <h5 class="fw-bold mb-4"><i class="bi bi-plus-circle me-2"></i>Create New Ticket</h5>

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <form id="new-ticket-form">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label">Customer Email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" id="nt-customer-email" required placeholder="customer@example.com">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Customer Name</label>
                                <input type="text" class="form-control" id="nt-customer-name" placeholder="Full Name">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Subject <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="nt-subject" required placeholder="Brief description of the issue">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Priority</label>
                                <select class="form-select" id="nt-priority">
                                    <option value="normal" selected>Normal</option>
                                    <option value="low">Low</option>
                                    <option value="high">High</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Channel</label>
                                <select class="form-select" id="nt-channel">
                                    <option value="phone">Phone</option>
                                    <option value="email">Email</option>
                                    <option value="web">Web</option>
                                    <option value="portal">Portal</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Assign To</label>
                                <select class="form-select" id="nt-assigned">
                                    <option value="">Unassigned</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Message <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="nt-body" rows="6" required placeholder="Describe the issue…"></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Attachments</label>
                                <input type="file" class="form-control" id="nt-files" multiple>
                            </div>
                            ${this._params.parent_id ? `
                            <div class="col-12">
                                <div class="alert alert-info mb-0 py-2">
                                    <i class="bi bi-diagram-2 me-2"></i>This will be created as a child ticket of #${App.escapeHtml(String(this._params.parent_id))}
                                </div>
                            </div>` : ''}
                        </div>
                        <div class="d-flex justify-content-end gap-2 mt-4">
                            <a href="#/tickets" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary" id="nt-submit">
                                <span class="spinner-border spinner-border-sm d-none me-1" id="nt-spinner"></span>
                                <i class="bi bi-plus-lg me-1"></i>Create Ticket
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>`;
    },

    async init(params) {
        this._params = params || {};

        // Load agents
        try {
            const res = await API.get('/agents');
            (res.data || []).forEach(a => {
                $('#nt-assigned').append(`<option value="${a.id}">${App.escapeHtml(a.name)}</option>`);
            });
            // Default assign to self
            if (API.currentUser && API.currentUser.id) {
                $('#nt-assigned').val(API.currentUser.id);
            }
        } catch (e) {}

        // Pre-fill parent if coming from child creation
        if (params && params.parent_id) {
            this._parentId = params.parent_id;
        }

        $('#new-ticket-form').on('submit', (e) => {
            e.preventDefault();
            this.submit();
        });
    },

    async submit() {
        const email    = $('#nt-customer-email').val().trim();
        const name     = $('#nt-customer-name').val().trim();
        const subject  = $('#nt-subject').val().trim();
        const body     = $('#nt-body').val().trim();
        const priority = $('#nt-priority').val();
        const channel  = $('#nt-channel').val();
        const assigned = $('#nt-assigned').val();
        const files    = document.getElementById('nt-files').files;

        $('#nt-spinner').removeClass('d-none');
        $('#nt-submit').prop('disabled', true);

        try {
            const payload = {
                customer_email: email,
                customer_name:  name || undefined,
                subject,
                body,
                priority,
                channel,
                assigned_agent_id: assigned || undefined,
            };
            if (this._parentId) payload.parent_ticket_id = this._parentId;

            const res = await API.post('/tickets', payload);
            const ticketId = res.data.id;

            // Upload attachments
            for (const file of files) {
                const fd = new FormData();
                fd.append('file', file);
                await API.upload('/tickets/' + ticketId + '/attachments', fd);
            }

            App.toast('Ticket created successfully', 'success');
            App.navigate('/tickets/' + ticketId);
        } catch (e) {
            App.toast(e.message, 'error');
        } finally {
            $('#nt-spinner').addClass('d-none');
            $('#nt-submit').prop('disabled', false);
        }
    }
};
