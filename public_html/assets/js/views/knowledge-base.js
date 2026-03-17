/**
 * Knowledge Base List View
 */
const KnowledgeBaseView = {
    render() {
        return `
        <div class="container-fluid p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4 class="mb-0"><i class="bi bi-book me-2"></i>Knowledge Base</h4>
                <div class="d-flex gap-2">
                    ${API.can('can_manage_kb') ? `<button class="btn btn-outline-secondary btn-sm" id="btn-manage-categories">
                        <i class="bi bi-tags me-1"></i>Categories
                    </button>` : ''}
                    ${API.isAgent() ? `<a href="#" id="btn-new-article" class="btn btn-primary btn-sm">
                        <i class="bi bi-plus-lg me-1"></i>New Article
                    </a>` : ''}
                </div>
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

        <!-- Categories Modal -->
        <div class="modal fade" id="categoriesModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-tags me-2"></i>Manage Categories</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div id="cat-modal-error" class="alert alert-danger d-none"></div>
                        <form id="cat-add-form" class="d-flex gap-2 mb-3">
                            <input type="text" class="form-control form-control-sm" id="cat-new-name" placeholder="New category name…" required>
                            <input type="number" class="form-control form-control-sm" id="cat-new-order" placeholder="Order" style="width:80px;" value="0" min="0">
                            <button type="submit" class="btn btn-primary btn-sm text-nowrap">
                                <i class="bi bi-plus-lg me-1"></i>Add
                            </button>
                        </form>
                        <div id="cat-list"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Move Articles Modal (shown when deleting a category that has articles) -->
        <div class="modal fade" id="moveCatModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Move Articles Before Deleting</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>The category <strong id="move-cat-name"></strong> has <strong id="move-cat-count"></strong> article(s). Where should they be moved?</p>
                        <select class="form-select" id="move-cat-target">
                            <option value="">Uncategorized</option>
                        </select>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-danger" id="btn-confirm-delete-cat">
                            Move &amp; Delete
                        </button>
                    </div>
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

    async init(params = {}) {
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

        $('#btn-manage-categories').on('click', () => this.openCategoriesModal());
        $('#btn-new-article').on('click', (e) => { e.preventDefault(); this.openNewModal(); });
        $('#btn-save-article').on('click', () => this.saveArticle());

        await this.load();

        // Auto-open edit modal if navigated here with ?edit=ID
        if (params.edit) {
            await this.openEditModal(parseInt(params.edit));
        }
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
                            ${API.isAdmin() ? `<button class="btn btn-sm btn-outline-danger btn-delete-article" data-id="${a.id}" data-title="${App.escapeHtml(a.title)}"><i class="bi bi-trash"></i></button>` : ''}
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

        $('.btn-delete-article').on('click', (e) => {
            e.stopPropagation();
            const btn = $(e.currentTarget);
            if (!confirm(`Delete article "${btn.data('title')}"? This cannot be undone.`)) return;
            this.deleteArticle(btn.data('id'));
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
        $('#article-category').val('');
        $('#article-modal-error').addClass('d-none');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('articleModal')).show();
    },

    async openEditModal(id) {
        try {
            const res = await API.get('/kb/articles/' + id);
            const a = res.data;
            $('#article-modal-title').text('Edit Article');
            $('#article-id').val(a.id);
            $('#article-title').val(a.title);
            $('#article-body').val(a.body_html || '');
            $('#article-category').val(a.category_id || '');
            $('#article-modal-error').addClass('d-none');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('articleModal')).show();
        } catch (e) { App.toast(e.message, 'error'); }
    },

    async openCategoriesModal() {
        await this.reloadCategories();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('categoriesModal')).show();

        $('#cat-add-form').off('submit').on('submit', async (e) => {
            e.preventDefault();
            const name  = $('#cat-new-name').val().trim();
            const order = parseInt($('#cat-new-order').val()) || 0;
            if (!name) return;
            try {
                const res = await API.post('/kb/categories', { name, sort_order: order });
                this._categories = res.data || [];
                this.renderCategoryList();
                this.refreshCategoryDropdowns();
                $('#cat-new-name').val('');
                $('#cat-new-order').val('0');
            } catch (e) { $('#cat-modal-error').text(e.message).removeClass('d-none'); }
        });
    },

    async reloadCategories() {
        const res = await API.get('/kb/categories');
        this._categories = res.data || [];
        this.renderCategoryList();
        this.refreshCategoryDropdowns();
    },

    renderCategoryList() {
        const cats = this._categories || [];
        if (!cats.length) {
            $('#cat-list').html('<p class="text-muted small text-center">No categories yet.</p>');
            return;
        }

        const rows = cats.map(c => `
            <div class="d-flex align-items-center gap-2 mb-2" id="cat-row-${c.id}">
                <div class="flex-grow-1" id="cat-view-${c.id}">
                    <span class="fw-semibold">${App.escapeHtml(c.name)}</span>
                    <span class="text-muted small ms-2">${c.article_count || 0} article${c.article_count != 1 ? 's' : ''}</span>
                    <span class="text-muted small ms-1">· order: ${c.sort_order}</span>
                </div>
                <div class="d-none flex-grow-1" id="cat-edit-${c.id}">
                    <div class="d-flex gap-1">
                        <input type="text" class="form-control form-control-sm" id="cat-edit-name-${c.id}" value="${App.escapeHtml(c.name)}">
                        <input type="number" class="form-control form-control-sm" id="cat-edit-order-${c.id}" value="${c.sort_order}" style="width:70px;" min="0">
                    </div>
                </div>
                <div id="cat-btns-${c.id}">
                    <button class="btn btn-sm btn-outline-secondary btn-cat-edit" data-id="${c.id}"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-danger btn-cat-delete" data-id="${c.id}" data-name="${App.escapeHtml(c.name)}" data-count="${c.article_count || 0}"><i class="bi bi-trash"></i></button>
                </div>
                <div class="d-none" id="cat-save-btns-${c.id}">
                    <button class="btn btn-sm btn-primary btn-cat-save" data-id="${c.id}">Save</button>
                    <button class="btn btn-sm btn-secondary btn-cat-cancel" data-id="${c.id}">Cancel</button>
                </div>
            </div>`).join('');

        $('#cat-list').html(rows);

        $('.btn-cat-edit').on('click', (e) => {
            const id = $(e.currentTarget).data('id');
            $(`#cat-view-${id}`).addClass('d-none');
            $(`#cat-edit-${id}`).removeClass('d-none');
            $(`#cat-btns-${id}`).addClass('d-none');
            $(`#cat-save-btns-${id}`).removeClass('d-none');
        });

        $('.btn-cat-cancel').on('click', (e) => {
            const id = $(e.currentTarget).data('id');
            $(`#cat-view-${id}`).removeClass('d-none');
            $(`#cat-edit-${id}`).addClass('d-none');
            $(`#cat-btns-${id}`).removeClass('d-none');
            $(`#cat-save-btns-${id}`).addClass('d-none');
        });

        $('.btn-cat-save').on('click', async (e) => {
            const id    = $(e.currentTarget).data('id');
            const name  = $(`#cat-edit-name-${id}`).val().trim();
            const order = parseInt($(`#cat-edit-order-${id}`).val()) || 0;
            if (!name) return;
            try {
                const res = await API.put('/kb/categories/' + id, { name, sort_order: order });
                this._categories = res.data || [];
                this.renderCategoryList();
                this.refreshCategoryDropdowns();
                await this.load();
            } catch (e) { App.toast(e.message, 'error'); }
        });

        $('.btn-cat-delete').on('click', (e) => {
            const btn   = $(e.currentTarget);
            const id    = btn.data('id');
            const name  = btn.data('name');
            const count = parseInt(btn.data('count')) || 0;
            this.confirmDeleteCategory(id, name, count);
        });
    },

    confirmDeleteCategory(id, name, articleCount) {
        if (articleCount > 0) {
            // Show move modal
            $('#move-cat-name').text(name);
            $('#move-cat-count').text(articleCount);

            const $sel = $('#move-cat-target');
            $sel.empty().append('<option value="">Uncategorized</option>');
            (this._categories || []).forEach(c => {
                if (c.id != id) {
                    $sel.append(`<option value="${c.id}">${App.escapeHtml(c.name)}</option>`);
                }
            });

            bootstrap.Modal.getOrCreateInstance(document.getElementById('moveCatModal')).show();

            $('#btn-confirm-delete-cat').off('click').on('click', async () => {
                const moveTo = $('#move-cat-target').val();
                bootstrap.Modal.getInstance(document.getElementById('moveCatModal')).hide();
                await this.deleteCategory(id, moveTo !== '' ? moveTo : null);
            });
        } else {
            if (!confirm(`Delete category "${name}"?`)) return;
            this.deleteCategory(id, null);
        }
    },

    async deleteCategory(id, moveToCategoryId) {
        try {
            const res = await API.delete('/kb/categories/' + id,
                moveToCategoryId !== undefined ? { move_to_category_id: moveToCategoryId } : {}
            );
            this._categories = res.data || [];
            this.renderCategoryList();
            this.refreshCategoryDropdowns();
            await this.load();
            App.toast('Category deleted');
        } catch (e) { App.toast(e.message, 'error'); }
    },

    refreshCategoryDropdowns() {
        const cats = this._categories || [];
        $('#kb-category, #article-category').each(function() {
            const current = $(this).val();
            $(this).find('option:not(:first)').remove();
            cats.forEach(c => {
                $(this).append(`<option value="${c.id}">${App.escapeHtml(c.name)}</option>`);
            });
            $(this).val(current);
        });
    },

    async deleteArticle(id) {
        try {
            await API.delete('/kb/articles/' + id);
            App.toast('Article deleted');
            await this.load();
        } catch (e) {
            App.toast(e.message, 'error');
        }
    },

    async saveArticle() {
        const id      = $('#article-id').val();
        const title   = $('#article-title').val().trim();
        const body    = $('#article-body').val().trim();
        const catId   = $('#article-category').val();
        if (!title || !body) {
            $('#article-modal-error').text('Title and content are required').removeClass('d-none');
            return;
        }

        const payload = { title, body_html: body, is_published: true, category_id: catId || null };

        $('#article-spinner').removeClass('d-none');
        $('#btn-save-article').prop('disabled', true);
        $('#article-modal-error').addClass('d-none');

        try {
            if (id) {
                await API.put('/kb/articles/' + id, payload);
            } else {
                await API.post('/kb/articles', payload);
            }
            bootstrap.Modal.getOrCreateInstance(document.getElementById('articleModal')).hide();
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
                    ${canEdit ? `<button class="btn btn-sm btn-outline-secondary" onclick="App.navigate('/kb?edit=${a.id}')">
                        <i class="bi bi-pencil me-1"></i>Edit
                    </button>` : ''}
                </div>
                <div class="text-muted small mb-4">
                    ${a.category_name ? `<span class="badge bg-light text-dark border me-2">${App.escapeHtml(a.category_name)}</span>` : ''}
                    Updated ${App.formatDate(a.updated_at)}
                </div>
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="article-body" style="white-space:pre-wrap;">${a.body_html || App.escapeHtml(a.body || '')}</div>
                    </div>
                </div>`);
        } catch (e) {
            $('#kb-article-wrap').html('<div class="alert alert-danger">' + App.escapeHtml(e.message) + '</div>');
        }
    }
};
