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
    _mentionBlotRegistered: false,

    _registerMentionBlot() {
        if (this._mentionBlotRegistered) return;
        this._mentionBlotRegistered = true;
        const EmbedBlot = Quill.import('blots/embed');
        class MentionBlot extends EmbedBlot {
            static create(value) {
                const node = super.create(value);
                const id   = parseInt(value.id, 10);
                node.setAttribute('class', 'mention mention-' + id);
                node.textContent = '@' + value.name;
                return node;
            }
            static value(node) {
                const cls = [...(node.classList || [])].find(c => /^mention-\d+$/.test(c));
                return {
                    id:   cls ? parseInt(cls.split('-')[1], 10) : 0,
                    name: (node.textContent || '').replace(/^@/, ''),
                };
            }
        }
        MentionBlot.blotName  = 'mention';
        MentionBlot.tagName   = 'span';
        MentionBlot.className = 'mention';
        Quill.register(MentionBlot);
    },

    /**
     * Like init(), but wires up @mention autocomplete.
     * agents: array of { id, name } objects.
     */
    initWithMentions(id, options, agents) {
        this._registerMentionBlot();
        const quill = this.init(id, options);
        if (!quill || !agents || !agents.length) return quill;

        let selectedIndex = -1;

        // Append dropdown inside the Quill container so getBounds() coords line up
        const $container = $(quill.container);
        $container.css('position', 'relative');
        const $drop = $('<div class="mention-dropdown d-none"></div>').appendTo($container);

        const filtered = q => {
            if (!q) return agents.slice(0, 8);
            const lq = q.toLowerCase();
            return agents.filter(a => a.name.toLowerCase().includes(lq)).slice(0, 8);
        };

        const hideDrop = () => {
            $drop.addClass('d-none').empty();
            selectedIndex = -1;
        };

        const showDrop = (cursorIndex, query) => {
            const list = filtered(query);
            if (!list.length) { hideDrop(); return; }

            selectedIndex = 0;
            $drop.empty();
            list.forEach((a, i) => {
                $('<div class="mention-item">')
                    .text('@' + a.name)
                    .toggleClass('active', i === 0)
                    .on('mousedown', e => { e.preventDefault(); pick(a); })
                    .appendTo($drop);
            });
            $drop.data('list', list);

            const b = quill.getBounds(cursorIndex);
            $drop.css({ top: b.top + b.height + 4, left: Math.max(0, b.left) }).removeClass('d-none');
        };

        const pick = agent => {
            const sel = quill.getSelection();
            if (!sel) return;
            const before = quill.getText(0, sel.index);
            const m      = before.match(/@(\w*)$/);
            if (!m) return;
            const from = sel.index - m[0].length;
            quill.deleteText(from, m[0].length, 'api');
            quill.insertEmbed(from, 'mention', { id: agent.id, name: agent.name }, 'api');
            quill.insertText(from + 1, ' ', 'api');
            quill.setSelection(from + 2, 0, 'api');
            hideDrop();
        };

        // Show / update dropdown as the user types
        quill.on('text-change', (_d, _o, source) => {
            if (source !== 'user') return;
            const sel = quill.getSelection();
            if (!sel) { hideDrop(); return; }
            const m = quill.getText(0, sel.index).match(/@(\w*)$/);
            if (m) showDrop(sel.index, m[1]);
            else   hideDrop();
        });

        // Keyboard navigation inside dropdown
        $(quill.root).on('keydown.mention', e => {
            if ($drop.hasClass('d-none')) return;
            const items = $drop.find('.mention-item');
            if (!items.length) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                selectedIndex = (selectedIndex + 1) % items.length;
                items.removeClass('active').eq(selectedIndex).addClass('active');
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                selectedIndex = (selectedIndex - 1 + items.length) % items.length;
                items.removeClass('active').eq(selectedIndex).addClass('active');
            } else if (e.key === 'Enter' || e.key === 'Tab') {
                const list = $drop.data('list');
                if (list && list[selectedIndex]) {
                    e.preventDefault();
                    e.stopPropagation();
                    pick(list[selectedIndex]);
                }
            } else if (e.key === 'Escape') {
                hideDrop();
            }
        });

        // Click outside closes dropdown — unbind first to prevent accumulation on re-init
        const evtNs = 'mousedown.mention-' + id;
        $(document).off(evtNs).on(evtNs, e => {
            if (!$drop.is(e.target) && !$drop.has(e.target).length) hideDrop();
        });

        return quill;
    },

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
