/**
 * RichEditor — thin Quill 2.x wrapper for Andrea Helpdesk.
 *
 * Usage:
 *   RichEditor.init('my-textarea', { placeholder: '…', minHeight: '120px', simple: false, value: '<p>…</p>' });
 *   RichEditor.get('my-textarea')      → sanitized HTML string
 *   RichEditor.getText('my-textarea')  → plain text (for isEmpty checks / server validation)
 *   RichEditor.set('my-textarea', html)
 *   RichEditor.clear('my-textarea')
 *   RichEditor.isEmpty('my-textarea')
 *
 * The underlying <textarea> is hidden and kept in sync so existing code that
 * reads el.value (e.g. settings.js save()) continues to work unchanged.
 */
const RichEditor = {
    _editors: {},

    init(id, options = {}) {
        const el = document.getElementById(id);
        if (!el) return null;

        // Reuse existing instance if its container is still in the DOM
        if (this._editors[id]) {
            const existing = document.getElementById(id + '-quill');
            if (existing && existing.isConnected) {
                if (options.value !== undefined) this.set(id, options.value);
                return this._editors[id];
            }
            delete this._editors[id];
        }

        // Insert the Quill container after the hidden textarea
        const container = document.createElement('div');
        container.id = id + '-quill';
        el.parentNode.insertBefore(container, el.nextSibling);
        el.style.display = 'none';

        const toolbar = options.simple ? [
            ['bold', 'italic', 'underline'],
            [{ list: 'ordered' }, { list: 'bullet' }],
            ['link'],
        ] : [
            ['bold', 'italic', 'underline'],
            [{ list: 'ordered' }, { list: 'bullet' }],
            ['link', 'blockquote'],
            ['clean'],
        ];

        const quill = new Quill('#' + id + '-quill', {
            theme: 'snow',
            modules: { toolbar },
            placeholder: options.placeholder || '',
        });

        if (options.value) {
            quill.root.innerHTML = DOMPurify.sanitize(options.value);
            el.value = options.value;
        }

        if (options.minHeight) {
            container.querySelector('.ql-editor').style.minHeight = options.minHeight;
        }

        // Keep hidden textarea in sync so el.value always reflects current content
        quill.on('text-change', () => {
            el.value = quill.root.innerHTML;
        });

        this._editors[id] = quill;
        return quill;
    },

    get(id) {
        const q = this._editors[id];
        if (!q) return document.getElementById(id)?.value || '';
        return DOMPurify.sanitize(q.root.innerHTML);
    },

    getText(id) {
        const q = this._editors[id];
        return q ? q.getText().trim() : (document.getElementById(id)?.value.trim() || '');
    },

    set(id, html) {
        const q = this._editors[id];
        if (q) q.root.innerHTML = DOMPurify.sanitize(html || '');
    },

    clear(id) {
        const q = this._editors[id];
        if (q) {
            q.setContents([]);
        } else {
            const el = document.getElementById(id);
            if (el) el.value = '';
        }
    },

    isEmpty(id) {
        return !this.getText(id);
    },
};
