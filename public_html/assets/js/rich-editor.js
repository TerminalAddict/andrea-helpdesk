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
    _emojiPickerEl: null,
    _emojiPickerBound: false,
    _activeEmojiContext: null,
    _emojiGroups: [
        { label: 'Smileys', emojis: ['😀','😁','😂','🤣','😊','🙂','😉','😍','😘','😎','🤩','🥳','🤔','😴','😢','😭','😡','👍','👎','👏','🙏'] },
        { label: 'People', emojis: ['👋','🙌','👌','✌️','🤝','💪','🫶','🧠','👀','❤️','💙','💚','🔥','✨','⭐','🎉','✅','❌'] },
        { label: 'Objects', emojis: ['📎','📌','📍','💡','📞','💻','🖥️','📱','⌚','🔒','🔑','🧾','📦','🚨','⚠️','🛠️','🔧','🧹'] },
    ],

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

    _ensureEmojiPicker(hostEl = null) {
        if (this._emojiPickerEl) return this._emojiPickerEl;

        const picker = document.createElement('div');
        picker.className = 'quill-emoji-picker-card d-none';
        picker.innerHTML = `
            <div class="quill-emoji-picker-header">Emoji</div>
            <div class="quill-emoji-picker-groups">
                ${this._emojiGroups.map(group => `
                    <div class="quill-emoji-group">
                        <div class="quill-emoji-group-label">${group.label}</div>
                        <div class="quill-emoji-grid">
                            ${group.emojis.map(emoji => `<button type="button" class="quill-emoji-btn" data-emoji="${emoji}" aria-label="${emoji}">${emoji}</button>`).join('')}
                        </div>
                    </div>
                `).join('')}
            </div>`;

        (hostEl || document.body).appendChild(picker);
        picker.addEventListener('click', (e) => {
            const btn = e.target.closest('.quill-emoji-btn');
            if (!btn) return;
            e.preventDefault();
            this.insertEmoji(btn.dataset.emoji || '');
        });

        if (!this._emojiPickerBound) {
            this._emojiPickerBound = true;
            document.addEventListener('mousedown', (e) => {
                if (!this._emojiPickerEl || this._emojiPickerEl.classList.contains('d-none')) return;
                if (this._emojiPickerEl.contains(e.target)) return;
                const toolbarButton = this._activeEmojiContext?.button;
                if (toolbarButton && toolbarButton.contains(e.target)) return;
                this.closeEmojiPicker();
            });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') this.closeEmojiPicker();
            });
        }

        this._emojiPickerEl = picker;
        return picker;
    },

    positionEmojiPicker() {
        return;
    },

    toggleEmojiPicker(quill, button) {
        if (!quill || !button) return;
        const hostEl = button.closest('.ql-formats') || button.parentElement || document.body;
        const picker = this._ensureEmojiPicker(hostEl);
        if (picker.parentNode !== hostEl) {
            hostEl.appendChild(picker);
        }
        if (!picker.classList.contains('d-none') && this._activeEmojiContext?.quill === quill) {
            this.closeEmojiPicker();
            return;
        }
        this._activeEmojiContext = { quill, button };
        picker.classList.remove('d-none');
    },

    closeEmojiPicker() {
        if (!this._emojiPickerEl) return;
        this._emojiPickerEl.classList.add('d-none');
        this._activeEmojiContext = null;
    },

    insertEmoji(emoji) {
        const quill = this._activeEmojiContext?.quill;
        if (!quill || !emoji) return;
        const range = quill.getSelection(true);
        const index = range ? range.index : quill.getLength();
        quill.insertText(index, emoji, 'user');
        quill.setSelection(index + emoji.length, 0, 'silent');
        quill.focus();
        this.closeEmojiPicker();
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

        const modules = {
            toolbar: {
                container: toolbar,
                handlers: {},
            },
        };

        const quill = new Quill('#' + id + '-quill', {
            theme: 'snow',
            modules,
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

        const toolbarModule = quill.getModule('toolbar');
        const toolbarEl = toolbarModule?.container
            || (container.previousElementSibling?.classList?.contains('ql-toolbar') ? container.previousElementSibling : null);
        if (toolbarEl) {
            const formatGroup = document.createElement('span');
            formatGroup.className = 'ql-formats quill-emoji-host';

            const emojiButton = document.createElement('button');
            emojiButton.type = 'button';
            emojiButton.className = 'ql-emoji';
            emojiButton.title = 'Insert emoji';
            emojiButton.setAttribute('aria-label', 'Insert emoji');
            emojiButton.textContent = '😀';
            emojiButton.addEventListener('click', (e) => {
                e.preventDefault();
                this.toggleEmojiPicker(quill, emojiButton);
            });

            formatGroup.appendChild(emojiButton);
            toolbarEl.appendChild(formatGroup);
        }

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
