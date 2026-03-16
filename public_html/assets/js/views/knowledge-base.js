/**
 * Knowledge Base List View
 */
const KnowledgeBaseView = {
    render() {
        return `
        <div class="container-fluid p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4 class="mb-0"><i class="bi bi-book me-2"></i>Knowledge Base</h4>
                ${API.isAgent() ? `<a href="#" id="btn-new-article" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg me-1"></i>New Article
                </a>` : ''}
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body py-2">
                    <div class="row g-2">
                        <div class="col-md-5">
                            <input type="search" class="form-control form-control-sm" id="kb-search" placeholder="Search articles…">
                        </div>
                        <div class="col-md-3">
                            <select class="form-select form-select-sm" id="kb-category">
                                <option value="">All Categories</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div id="kb-list">
                <div class="text-center py-5 text-muted">
                    <div class="spinner-border"></div><p class="mt-2">Loading…</p>
                </div>
            </div>
        </div>

        <!-- New/Edit Article Modal -->
        <div class="modal fade" id="articleModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="article-modal-title">New Article</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div id="article-modal-error" class="alert alert-danger d-none"></div>
                        <input type="hidden" id="article-id">
                        <div class="mb-3">
                            <label class="form-label">Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="article-title" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Category</label>
                            <select class="form-select" id="article-category">
                                <option value="">Uncategorized</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Content <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="article-body" rows="10" required></textarea>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="article-published" checked>
                            <label class="form-check-label" for="article-published">Published</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="btn-save-article">
                            <span class="spinner-border spinner-border-sm d-none me-1" id="article-spinner"></span>
                            Save Article
                        </button>
                    </div>
                </div>
            </div>
        </div>`;
    },

    async init() {
        // Load categories
        try {
            const res = await API.get('/kb/categories');
            (res.data || []).forEach(c => {
                $('#kb-category, #article-category').append(`<option value="${c.id}">${App.escapeHtml(c.name)}</option>`);
            });
        } catch (e) {}

        let searchTimer;
        $('#kb-search').on('input', () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => this.load(), 400);
        });
        $('#kb-category').on('change', () => this.load());

        $('#btn-new-article').on('click', (e) => { e.preventDefault(); this.openNewModal(); });
        $('#btn-save-article').on('click', () => this.saveArticle());

        await this.load();
    },

    async load(page = 1) {
        const params = { page, per_page: 20 };
        const q    = $('#kb-search').val().trim();
        const cat  = $('#kb-category').val();
        if (q)   params.q = q;
        if (cat) params.category_id = cat;

        try {
            const res = await API.get('/kb/articles', params);
            this.renderList(res.data || [], res.meta || {}, page);
        } catch (e) {
            $('#kb-list').html('<div class="alert alert-danger">' + App.escapeHtml(e.message) + '</div>');
        }
    },

    renderList(articles, meta, currentPage) {
        if (!articles.length) {
            $('#kb-list').html('<p class="text-muted text-center p-4">No articles found.</p>');
            return;
        }

        const cards = articles.map(a => `
            <div class="card border-0 shadow-sm mb-2 kb-article" data-slug="${App.escapeHtml(a.slug)}" style="cursor:pointer;">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="mb-1 fw-semibold">${App.escapeHtml(a.title)}</h6>
                            <div class="small text-muted">
                                ${a.category_name ? `<span class="badge bg-light text-dark border me-1">${App.escapeHtml(a.category_name)}</span>` : ''}
                                Updated ${App.formatDate(a.updated_at)}
                            </div>
                        </div>
                        <div class="d-flex gap-1 ms-2">
                            ${!a.is_published ? '<span class="badge bg-warning text-dark">Draft</span>' : ''}
                            ${API.isAgent() ? `<button class="btn btn-sm btn-outline-secondary btn-edit-article" data-id="${a.id}"><i class="bi bi-pencil"></i></button>` : ''}
                        </div>
                    </div>
                </div>
            </div>`).join('');

        let pagination = '';
        if (meta.last_page > 1) {
            const pages = Array.from({length: meta.last_page}, (_, i) => i + 1)
                .map(i => `<li class="page-item ${i === currentPage ? 'active' : ''}">
                    <a class="page-link" href="#" data-page="${i}">${i}</a></li>`)
                .join('');
            pagination = `<nav class="mt-3"><ul class="pagination pagination-sm justify-content-center">
                <li class="page-item ${currentPage <= 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${currentPage-1}">‹</a></li>
                ${pages}
                <li class="page-item ${currentPage >= meta.last_page ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${currentPage+1}">›</a></li>
            </ul></nav>`;
        }

        $('#kb-list').html(cards + pagination);

        $('.kb-article').on('click', function(e) {
            if ($(e.target).closest('.btn-edit-article').length) return;
            App.navigate('/kb/' + $(this).data('slug'));
        });

        $('.btn-edit-article').on('click', (e) => {
            e.stopPropagation();
            this.openEditModal($(e.currentTarget).data('id'));
        });

        $('#kb-list').on('click', 'a[data-page]', (e) => {
            e.preventDefault();
            this.load(parseInt($(e.currentTarget).data('page')));
        });
    },

    openNewModal() {
        $('#article-modal-title').text('New Article');
        $('#article-id').val('');
        $('#article-title').val('');
        $('#article-body').val('');
        $('#article-published').prop('checked', true);
        $('#article-category').val('');
        $('#article-modal-error').addClass('d-none');
        new bootstrap.Modal(document.getElementById('articleModal')).show();
    },

    async openEditModal(id) {
        try {
            // Find by listing or fetch individually — use list data
            // Re-fetch from API
            const res = await API.get('/kb/articles/' + id);
            const a = res.data;
            $('#article-modal-title').text('Edit Article');
            $('#article-id').val(a.id);
            $('#article-title').val(a.title);
            $('#article-body').val(a.body);
            $('#article-published').prop('checked', !!a.is_published);
            $('#article-category').val(a.category_id || '');
            $('#article-modal-error').addClass('d-none');
            new bootstrap.Modal(document.getElementById('articleModal')).show();
        } catch (e) { App.toast(e.message, 'error'); }
    },

    async saveArticle() {
        const id      = $('#article-id').val();
        const title   = $('#article-title').val().trim();
        const body    = $('#article-body').val().trim();
        const catId   = $('#article-category').val();
        const publik  = $('#article-published').prop('checked');

        if (!title || !body) {
            $('#article-modal-error').text('Title and content are required').removeClass('d-none');
            return;
        }

        const payload = { title, body, is_published: publik, category_id: catId || null };

        $('#article-spinner').removeClass('d-none');
        $('#btn-save-article').prop('disabled', true);
        $('#article-modal-error').addClass('d-none');

        try {
            if (id) {
                await API.put('/kb/articles/' + id, payload);
            } else {
                await API.post('/kb/articles', payload);
            }
            bootstrap.Modal.getInstance(document.getElementById('articleModal')).hide();
            App.toast('Article saved');
            await this.load();
        } catch (e) {
            $('#article-modal-error').text(e.message).removeClass('d-none');
        } finally {
            $('#article-spinner').addClass('d-none');
            $('#btn-save-article').prop('disabled', false);
        }
    }
};

/**
 * Knowledge Base Article View
 */
const KbArticleView = {
    render() {
        return `
        <div class="container py-4" style="max-width:800px;" id="kb-article-wrap">
            <div class="text-center py-5 text-muted">
                <div class="spinner-border"></div>
            </div>
        </div>`;
    },

    async init(params) {
        try {
            const res = await API.get('/kb/articles/' + params.slug);
            const a = res.data;
            const canEdit = API.isAgent();

            $('#kb-article-wrap').html(`
                <nav aria-label="breadcrumb" class="mb-3">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="#/kb">Knowledge Base</a></li>
                        <li class="breadcrumb-item active">${App.escapeHtml(a.title)}</li>
                    </ol>
                </nav>
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <h3 class="fw-bold">${App.escapeHtml(a.title)}</h3>
                    ${canEdit ? `<button class="btn btn-sm btn-outline-secondary" onclick="KnowledgeBaseView.openEditModal(${a.id})">
                        <i class="bi bi-pencil me-1"></i>Edit
                    </button>` : ''}
                </div>
                <div class="text-muted small mb-4">
                    ${a.category_name ? `<span class="badge bg-light text-dark border me-2">${App.escapeHtml(a.category_name)}</span>` : ''}
                    Updated ${App.formatDate(a.updated_at)}
                </div>
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="article-body">${a.body_html || '<pre style="white-space:pre-wrap;">' + App.escapeHtml(a.body) + '</pre>'}</div>
                    </div>
                </div>`);
        } catch (e) {
            $('#kb-article-wrap').html('<div class="alert alert-danger">' + App.escapeHtml(e.message) + '</div>');
        }
    }
};
