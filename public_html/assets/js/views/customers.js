/**
 * Customers List View
 */
const CustomersView = {
    render() {
        return `
        <div class="container-fluid p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="mb-0"><i class="bi bi-people me-2"></i>Customers</h4>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-secondary btn-sm" id="btn-import-csv">
                        <i class="bi bi-upload me-1"></i>Import CSV
                    </button>
                    <button class="btn btn-primary btn-sm" id="btn-new-customer">
                        <i class="bi bi-person-plus me-1"></i>New Customer
                    </button>
                </div>
            </div>

            <!-- Create Customer Modal -->
            <div class="modal fade" id="createCustomerModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>New Customer</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="nc-name" placeholder="Full name">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" id="nc-email" placeholder="email@example.com">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Phone</label>
                                <input type="text" class="form-control" id="nc-phone" placeholder="Phone number">
                            </div>
                            <div class="mb-0">
                                <label class="form-label">Company</label>
                                <input type="text" class="form-control" id="nc-company" placeholder="Company name">
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary" id="nc-save">
                                <span class="spinner-border spinner-border-sm d-none me-1" id="nc-spinner"></span>
                                Create Customer
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Import CSV Modal -->
            <div class="modal fade" id="importCsvModal" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Import Customers from CSV</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-muted small mb-3">
                                Upload a CSV file with columns: <strong>name</strong>, <strong>email</strong>, <em>phone</em> (optional), <em>company</em> (optional).
                                Customers with an email that already exists will be skipped.
                            </p>
                            <div class="mb-3">
                                <a href="#" id="btn-download-template" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-download me-1"></i>Download Template CSV
                                </a>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">CSV File <span class="text-danger">*</span></label>
                                <input type="file" class="form-control" id="csv-file" accept=".csv,text/csv">
                            </div>
                            <div id="import-results" class="d-none"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="button" class="btn btn-primary" id="btn-do-import">
                                <span class="spinner-border spinner-border-sm d-none me-1" id="import-spinner"></span>
                                Import
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body py-2">
                    <div class="row g-2">
                        <div class="col-md-5">
                            <input type="search" class="form-control form-control-sm" id="cust-search" placeholder="Search by name or email…">
                        </div>
                        <div class="col-md-1">
                            <button class="btn btn-secondary btn-sm w-100" id="cust-reset">Clear</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-body p-0" id="customers-table-wrap">
                    <div class="text-center py-5 text-muted">
                        <div class="spinner-border"></div><p class="mt-2">Loading…</p>
                    </div>
                </div>
            </div>
            <div id="customers-pagination" class="mt-3"></div>
        </div>`;
    },

    async init() {
        let searchTimer;
        $('#cust-search').on('input', () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => this.load(), 400);
        });
        $('#cust-reset').on('click', () => {
            $('#cust-search').val('');
            this.load();
        });

        $('#btn-new-customer').on('click', () => {
            $('#nc-name, #nc-email, #nc-phone, #nc-company').val('');
            new bootstrap.Modal(document.getElementById('createCustomerModal')).show();
        });

        $('#btn-import-csv').on('click', () => {
            $('#csv-file').val('');
            $('#import-results').addClass('d-none').html('');
            $('#btn-do-import').removeClass('d-none').prop('disabled', false);
            new bootstrap.Modal(document.getElementById('importCsvModal')).show();
        });

        $('#btn-download-template').on('click', (e) => {
            e.preventDefault();
            const csv = 'name,email,phone,company\nJane Smith,jane@example.com,+64 9 123 4567,Acme Ltd\nJohn Doe,john@example.com,,';
            const blob = new Blob([csv], { type: 'text/csv' });
            const url  = URL.createObjectURL(blob);
            const a    = document.createElement('a');
            a.href = url; a.download = 'customers-template.csv';
            a.click();
            URL.revokeObjectURL(url);
        });

        $('#btn-do-import').on('click', () => this.importCsv());

        $('#nc-save').on('click', () => this.createCustomer());

        document.getElementById('createCustomerModal').addEventListener('keydown', (e) => {
            if (e.key === 'Enter') this.createCustomer();
        });

        this.load();
    },

    async createCustomer() {
        const name    = $('#nc-name').val().trim();
        const email   = $('#nc-email').val().trim();
        const phone   = $('#nc-phone').val().trim();
        const company = $('#nc-company').val().trim();

        if (!name)  { App.toast('Name is required', 'error'); return; }
        if (!email) { App.toast('Email is required', 'error'); return; }

        $('#nc-spinner').removeClass('d-none');
        $('#nc-save').prop('disabled', true);
        try {
            const res = await API.post('/customers', { name, email, phone: phone || undefined, company: company || undefined });
            bootstrap.Modal.getInstance(document.getElementById('createCustomerModal')).hide();
            App.toast('Customer created', 'success');
            App.navigate('/customers/' + res.data.id);
        } catch (e) {
            App.toast(e.message, 'error');
        } finally {
            $('#nc-spinner').addClass('d-none');
            $('#nc-save').prop('disabled', false);
        }
    },

    async importCsv() {
        const fileInput = document.getElementById('csv-file');
        if (!fileInput.files.length) {
            App.toast('Please select a CSV file', 'error');
            return;
        }

        const formData = new FormData();
        formData.append('csv', fileInput.files[0]);

        $('#import-spinner').removeClass('d-none');
        $('#btn-do-import').prop('disabled', true);
        $('#import-results').addClass('d-none').html('');

        try {
            const res = await API.upload('/customers/import', formData);
            const d = res.data;

            let html = `<div class="alert alert-success mb-2">
                <strong>${d.created_count}</strong> customer${d.created_count !== 1 ? 's' : ''} imported successfully.
            </div>`;

            if (d.skipped_count > 0) {
                const rows = d.skipped.map(s =>
                    `<tr><td class="small">${App.escapeHtml(s.email)}</td><td class="small text-muted">${App.escapeHtml(s.reason)}</td></tr>`
                ).join('');
                html += `<div class="alert alert-warning mb-0">
                    <strong>${d.skipped_count}</strong> row${d.skipped_count !== 1 ? 's' : ''} skipped:
                    <div class="table-responsive mt-2">
                        <table class="table table-sm table-bordered mb-0 bg-white">
                            <thead><tr><th>Email</th><th>Reason</th></tr></thead>
                            <tbody>${rows}</tbody>
                        </table>
                    </div>
                </div>`;
            }

            $('#import-results').removeClass('d-none').html(html);
            $('#btn-do-import').addClass('d-none');
            if (d.created_count > 0) this.load();
        } catch (e) {
            App.toast(e.message, 'error');
        } finally {
            $('#import-spinner').addClass('d-none');
            $('#btn-do-import').prop('disabled', false);
        }
    },

    async load(page = 1) {
        const params = { page, per_page: 25 };
        const q = $('#cust-search').val().trim();
        if (q) params.q = q;

        try {
            const res = await API.get('/customers', params);
            this.renderTable(res.data || [], res.meta || {});
            this.renderPagination(res.meta || {}, page);
        } catch (e) {
            $('#customers-table-wrap').html('<p class="text-danger p-3">' + App.escapeHtml(e.message) + '</p>');
        }
    },

    renderTable(customers, meta) {
        if (!customers.length) {
            $('#customers-table-wrap').html('<p class="text-muted p-4 text-center">No customers found.</p>');
            return;
        }

        const total = meta.total || 0;
        const rows = customers.map(c => `
            <tr class="cust-row" data-id="${c.id}" style="cursor:pointer;">
                <td>${App.escapeHtml(c.name || '–')}</td>
                <td class="small">${App.escapeHtml(c.email)}</td>
                <td class="small text-muted">${App.escapeHtml(c.phone || '–')}</td>
                <td class="small text-muted">${c.ticket_count || 0}</td>
                <td class="small text-muted text-nowrap">${App.formatDate(c.created_at)}</td>
            </tr>`).join('');

        $('#customers-table-wrap').html(`
            <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom bg-light">
                <span class="small text-muted">${total} customer${total !== 1 ? 's' : ''}</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th><th>Email</th><th>Phone</th><th>Tickets</th><th>Since</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>`);

        $('.cust-row').on('click', function() {
            App.navigate('/customers/' + $(this).data('id'));
        });
    },

    renderPagination(meta, currentPage) {
        if (!meta.last_page || meta.last_page <= 1) {
            $('#customers-pagination').empty();
            return;
        }
        let pages = '';
        for (let i = 1; i <= meta.last_page; i++) {
            if (Math.abs(i - currentPage) > 3 && i !== 1 && i !== meta.last_page) {
                if (i === currentPage - 4 || i === currentPage + 4) pages += '<li class="page-item disabled"><span class="page-link">…</span></li>';
                continue;
            }
            pages += `<li class="page-item ${i === currentPage ? 'active' : ''}"><a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
        }
        $('#customers-pagination').html(`
            <nav><ul class="pagination pagination-sm justify-content-center">
                <li class="page-item ${currentPage <= 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${currentPage - 1}">‹</a></li>
                ${pages}
                <li class="page-item ${currentPage >= meta.last_page ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${currentPage + 1}">›</a></li>
            </ul></nav>`);

        $('#customers-pagination').on('click', 'a[data-page]', (e) => {
            e.preventDefault();
            this.load(parseInt($(e.currentTarget).data('page')));
        });
    }
};
