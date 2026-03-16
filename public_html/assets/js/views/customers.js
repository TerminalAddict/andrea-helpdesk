/**
 * Customers List View
 */
const CustomersView = {
    render() {
        return `
        <div class="container-fluid p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="mb-0"><i class="bi bi-people me-2"></i>Customers</h4>
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
        this.load();
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
