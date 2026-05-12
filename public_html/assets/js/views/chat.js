/**
 * Internal agent chat.
 */
const ChatView = {
    channels: [],
    threads: [],
    agents: [],
    selected: null,
    messages: [],
    socket: null,
    socketManuallyClosed: false,
    socketAuthed: false,
    pollTimer: null,
    reconnectTimer: null,
    routeCleanupBound: false,
    lastMessageId: 0,
    emojiGroups: [
        { label: 'Smileys', emojis: ['😀','😁','😂','🤣','😊','🙂','😉','😍','😘','😎','🤩','🥳','🤔','😴','😢','😭','😡','👍','👎','👏','🙏'] },
        { label: 'People', emojis: ['👋','🙌','👌','✌️','🤝','💪','🫶','🧠','👀','❤️','💙','💚','🔥','✨','⭐','🎉','✅','❌'] },
        { label: 'Objects', emojis: ['📎','📌','📍','💡','📞','💻','🖥️','📱','⌚','🔒','🔑','🧾','📦','🚨','⚠️','🛠️','🔧','🧹'] },
    ],
    emoticonMap: [
        [':-)', '🙂'], [':)', '🙂'], ['=)', '🙂'],
        [';-)', '😉'], [';)', '😉'],
        [':-D', '😃'], [':D', '😃'], ['=D', '😃'],
        ['xD', '😆'], ['XD', '😆'],
        [':-P', '😛'], [':P', '😛'], [':-p', '😛'], [':p', '😛'], ['=P', '😛'], ['=p', '😛'],
        [':-O', '😮'], [':O', '😮'], [':-o', '😮'], [':o', '😮'],
        [':-(', '🙁'], [':(', '🙁'], ['=(', '🙁'],
        [":'-)", '🥲'], [":')", '🥲'],
        [":'-(", '😢'], [":'(", '😢'],
        [':-/', '😕'], [':/', '😕'], [':-\\', '😕'], [':\\', '😕'],
        [':-|', '😐'], [':|', '😐'],
        [':-*', '😘'], [':*', '😘'],
        [':-@', '😡'], [':@', '😡'],
        [':-$', '😳'], [':$', '😳'],
        ['<3', '❤️'], ['</3', '💔'],
        [':+1:', '👍'], [':-1:', '👎'],
    ],

    render() {
        return `
            <div class="container-fluid terminal-screen p-4 terminal-compact chat-screen">
                <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-3">
                    <h4 class="terminal-heading mb-0"><i class="bi bi-chat-dots me-2"></i>Chat</h4>
                    <div id="chat-connection-state" class="badge text-bg-secondary">Connecting</div>
                </div>
                <div class="row g-3">
                    <div class="col-lg-3">
                        <div class="card border-0 shadow-sm chat-sidebar">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="mb-0">Channels</h6>
                                    <button class="btn btn-sm btn-outline-secondary" id="btn-refresh-chat"><i class="bi bi-arrow-repeat"></i></button>
                                </div>
                                <div id="chat-channel-list" class="list-group list-group-flush mb-3"></div>
                                <h6 class="mb-2">Direct Messages</h6>
                                <div class="input-group input-group-sm mb-2">
                                    <select class="form-select" id="chat-agent-select">
                                        <option value="">Start a DM…</option>
                                    </select>
                                    <button class="btn btn-outline-primary" id="btn-start-dm">Open</button>
                                </div>
                                <div id="chat-direct-list" class="list-group list-group-flush"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-9">
                        <div class="card border-0 shadow-sm chat-main">
                            <div class="card-header bg-white">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <div>
                                        <h5 class="mb-1" id="chat-title">Select a conversation</h5>
                                        <div class="text-muted small" id="chat-subtitle">Channels and direct messages are internal to agents.</div>
                                    </div>
                                    <div class="text-muted small" id="chat-typing"></div>
                                </div>
                            </div>
                            <div class="card-body chat-message-pane" id="chat-message-pane">
                                <div class="text-muted text-center py-5">Choose a channel or direct message.</div>
                            </div>
                            <div class="card-footer bg-white">
                                <form id="chat-compose-form" class="chat-compose d-none">
                                    <textarea class="form-control" id="chat-compose-body" rows="2" maxlength="4000" placeholder="Type a message. Emoji and links are supported."></textarea>
                                    <div class="d-flex justify-content-between align-items-center mt-2 gap-2">
                                        <small class="text-muted">Enter sends. Shift+Enter adds a new line. Use @handle for mentions. Ticket links: #123. KB links: kb:article-slug.</small>
                                        <div class="d-flex align-items-center gap-2 chat-compose-actions">
                                            <div class="chat-emoji-host">
                                                <button class="btn btn-outline-secondary" type="button" id="btn-chat-emoji" aria-label="Insert emoji">😀</button>
                                                ${this.renderEmojiPicker()}
                                            </div>
                                            <button class="btn btn-primary" type="submit"><i class="bi bi-send me-1"></i>Send</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
    },

    async init(params = {}) {
        this.stop();
        this.socketManuallyClosed = false;
        this.selected = null;
        this.messages = [];
        await this.loadLists();
        this.bindEvents();
        this.bindRouteCleanup();
        this.connectSocket();
        this.startPolling();

        if (params.thread_id) {
            this.selectDirect(parseInt(params.thread_id, 10));
        } else if (params.channel_id) {
            this.selectChannel(parseInt(params.channel_id, 10));
        } else if (this.channels.length) {
            this.selectChannel(parseInt(this.channels[0].id, 10));
        }
    },

    stop() {
        if (this.pollTimer) window.clearInterval(this.pollTimer);
        this.pollTimer = null;
        if (this.reconnectTimer) window.clearTimeout(this.reconnectTimer);
        this.reconnectTimer = null;
        if (this.socket) {
            this.socketManuallyClosed = true;
            try { this.socket.close(); } catch (e) {}
        }
        $(document).off('mousedown.chatEmoji keydown.chatEmoji');
        this.socket = null;
        this.socketAuthed = false;
    },

    async loadLists() {
        try {
            const [channels, threads, agents] = await Promise.all([
                API.get('/chat/channels'),
                API.get('/chat/direct'),
                API.get('/chat/agents'),
            ]);
            this.channels = (channels.data && channels.data.channels) || [];
            this.threads = (threads.data && threads.data.threads) || [];
            this.agents = (agents.data && agents.data.agents) || [];
            this.renderLists();
        } catch (e) {
            $('#chat-message-pane').html('<div class="alert alert-warning">' + App.escapeHtml(e.message) + '</div>');
        }
    },

    renderLists() {
        $('#chat-channel-list').html(this.channels.length ? this.channels.map(channel => `
            <button type="button" class="list-group-item list-group-item-action chat-channel-item" data-id="${channel.id}">
                <span class="fw-semibold"># ${App.escapeHtml(channel.name)}</span>
                ${parseInt(channel.unread_count || 0, 10) > 0 ? `<span class="badge text-bg-danger float-end">${channel.unread_count}</span>` : ''}
            </button>
        `).join('') : '<div class="text-muted small">No channels available.</div>');

        $('#chat-direct-list').html(this.threads.length ? this.threads.map(thread => `
            <button type="button" class="list-group-item list-group-item-action chat-direct-item" data-id="${thread.id}">
                <span class="fw-semibold">${App.escapeHtml(thread.other_agent_name || 'Agent')}</span>
                ${parseInt(thread.unread_count || 0, 10) > 0 ? `<span class="badge text-bg-danger float-end">${thread.unread_count}</span>` : ''}
            </button>
        `).join('') : '<div class="text-muted small">No direct messages yet.</div>');

        $('#chat-agent-select').html('<option value="">Start a DM…</option>' + this.agents
            .filter(agent => !agent.is_self)
            .map(agent => `<option value="${agent.id}">${App.escapeHtml(agent.name)}${agent.chat_handle ? ' (@' + App.escapeHtml(agent.chat_handle) + ')' : ''}</option>`)
            .join(''));
    },

    bindEvents() {
        $('#app')
            .off('.chat')
            .on('click.chat', '#btn-refresh-chat', async () => {
                await this.loadLists();
                if (this.selected) await this.loadMessages();
            })
            .on('click.chat', '.chat-channel-item', (e) => {
                this.selectChannel(parseInt($(e.currentTarget).data('id'), 10));
            })
            .on('click.chat', '.chat-direct-item', (e) => {
                this.selectDirect(parseInt($(e.currentTarget).data('id'), 10));
            })
            .on('click.chat', '#btn-start-dm', async () => {
                const agentId = parseInt($('#chat-agent-select').val(), 10);
                if (!agentId) return;
                try {
                    const res = await API.post('/chat/direct', { agent_id: agentId });
                    await this.loadLists();
                    this.selectDirect(parseInt(res.data.thread.id, 10));
                } catch (e) {
                    App.toast(e.message, 'error');
                }
            })
            .on('submit.chat', '#chat-compose-form', async (e) => {
                e.preventDefault();
                await this.sendMessage();
            })
            .on('keydown.chat', '#chat-compose-body', async (e) => {
                if (e.key !== 'Enter' || e.shiftKey || e.ctrlKey || e.altKey || e.metaKey || e.isComposing) {
                    return;
                }
                e.preventDefault();
                await this.sendMessage();
            })
            .on('click.chat', '#btn-chat-emoji', (e) => {
                e.preventDefault();
                this.toggleEmojiPicker();
            })
            .on('click.chat', '.chat-emoji-btn', (e) => {
                e.preventDefault();
                this.insertEmoji($(e.currentTarget).data('emoji') || '');
            })
            .on('input.chat', '#chat-compose-body', () => this.sendTyping());

        $(document)
            .off('mousedown.chatEmoji keydown.chatEmoji')
            .on('mousedown.chatEmoji', (e) => {
                const picker = document.getElementById('chat-emoji-picker');
                const button = document.getElementById('btn-chat-emoji');
                if (!picker || picker.classList.contains('d-none')) return;
                if (picker.contains(e.target) || button?.contains(e.target)) return;
                this.closeEmojiPicker();
            })
            .on('keydown.chatEmoji', (e) => {
                if (e.key === 'Escape') this.closeEmojiPicker();
            });
    },

    bindRouteCleanup() {
        if (this.routeCleanupBound) return;
        this.routeCleanupBound = true;
        window.addEventListener('hashchange', () => {
            const hash = window.location.hash.replace(/^#/, '') || '/';
            if (!hash.startsWith('/chat')) {
                this.stop();
            }
        });
    },

    async selectChannel(id) {
        const channel = this.channels.find(item => parseInt(item.id, 10) === id);
        if (!channel) return;
        this.selected = { scope: 'channel', id };
        App.navigate('/chat/channels/' + id);
        $('#chat-title').text('# ' + channel.name);
        $('#chat-subtitle').text(channel.description || 'Channel conversation');
        $('#chat-compose-form').removeClass('d-none');
        await this.loadMessages();
    },

    async selectDirect(id) {
        const thread = this.threads.find(item => parseInt(item.id, 10) === id);
        if (!thread) return;
        this.selected = { scope: 'direct', id };
        App.navigate('/chat/direct/' + id);
        $('#chat-title').text(thread.other_agent_name || 'Direct Message');
        $('#chat-subtitle').text(thread.other_agent_is_active === 0 ? 'Agent disabled. History is retained read-only.' : 'Private direct message');
        $('#chat-compose-form').toggleClass('d-none', thread.other_agent_is_active === 0);
        await this.loadMessages();
    },

    async loadMessages(afterId = null) {
        if (!this.selected) return;
        const path = this.selected.scope === 'channel'
            ? `/chat/channels/${this.selected.id}/messages`
            : `/chat/direct/${this.selected.id}/messages`;
        const res = await API.get(path, afterId ? { after_id: afterId, limit: 100 } : { limit: 100 });
        const incoming = (res.data && res.data.messages) || [];
        if (afterId) {
            this.messages = this.messages.concat(incoming);
        } else {
            this.messages = incoming.reverse();
        }
        this.lastMessageId = this.messages.reduce((max, message) => Math.max(max, parseInt(message.id, 10)), this.lastMessageId);
        this.renderMessages();
        this.markSelectedRead();
    },

    renderMessages() {
        if (!this.messages.length) {
            $('#chat-message-pane').html('<div class="text-muted text-center py-5">No messages yet.</div>');
            return;
        }
        $('#chat-message-pane').html(this.messages.map(message => {
            const own = parseInt(message.sender_agent_id, 10) === parseInt(API.currentUser?.id || 0, 10);
            const html = DOMPurify.sanitize(message.body_rendered_html || App.escapeHtml(message.body_text || ''));
            return `
                <article class="chat-message ${own ? 'chat-message-own' : ''}">
                    <div class="chat-message-meta">
                        <strong>${App.escapeHtml(message.sender_name || 'Agent')}</strong>
                        <span>${App.escapeHtml(App.formatDate(message.created_at))}</span>
                    </div>
                    <div class="chat-message-body">${html}</div>
                </article>
            `;
        }).join(''));
        const pane = document.getElementById('chat-message-pane');
        if (pane) pane.scrollTop = pane.scrollHeight;
    },

    async sendMessage() {
        if (!this.selected) return;
        const body = this.normalizeEmoticons(String($('#chat-compose-body').val() || '').trim());
        if (!body) return;
        if (this.socket && this.socket.readyState === WebSocket.OPEN && this.socketAuthed) {
            const event = this.selected.scope === 'channel'
                ? { type: 'chat.channel.message.send', channel_id: this.selected.id, body }
                : { type: 'chat.direct.message.send', thread_id: this.selected.id, body };
            this.socket.send(JSON.stringify(event));
            $('#chat-compose-body').val('');
            return;
        }

        const path = this.selected.scope === 'channel'
            ? `/chat/channels/${this.selected.id}/messages`
            : `/chat/direct/${this.selected.id}/messages`;
        try {
            const res = await API.post(path, { body });
            $('#chat-compose-body').val('');
            this.addMessage(res.data.message);
        } catch (e) {
            App.toast(e.message, 'error');
        }
    },

    renderEmojiPicker() {
        const groups = (typeof RichEditor !== 'undefined' && Array.isArray(RichEditor._emojiGroups))
            ? RichEditor._emojiGroups
            : this.emojiGroups;
        return `
            <div id="chat-emoji-picker" class="quill-emoji-picker-card chat-emoji-picker-card d-none">
                <div class="quill-emoji-picker-header">Emoji</div>
                <div class="quill-emoji-picker-groups">
                    ${groups.map(group => `
                        <div class="quill-emoji-group">
                            <div class="quill-emoji-group-label">${App.escapeHtml(group.label)}</div>
                            <div class="quill-emoji-grid">
                                ${group.emojis.map(emoji => `<button type="button" class="quill-emoji-btn chat-emoji-btn" data-emoji="${App.escapeHtml(emoji)}" aria-label="${App.escapeHtml(emoji)}">${App.escapeHtml(emoji)}</button>`).join('')}
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    },

    toggleEmojiPicker() {
        const picker = document.getElementById('chat-emoji-picker');
        if (!picker) return;
        picker.classList.toggle('d-none');
    },

    closeEmojiPicker() {
        document.getElementById('chat-emoji-picker')?.classList.add('d-none');
    },

    insertEmoji(emoji) {
        if (!emoji) return;
        const textarea = document.getElementById('chat-compose-body');
        if (!textarea) return;
        const start = textarea.selectionStart ?? textarea.value.length;
        const end = textarea.selectionEnd ?? start;
        textarea.value = textarea.value.slice(0, start) + emoji + textarea.value.slice(end);
        const next = start + emoji.length;
        textarea.setSelectionRange(next, next);
        textarea.focus();
        this.closeEmojiPicker();
        this.sendTyping();
    },

    normalizeEmoticons(text) {
        let normalized = String(text || '');
        const escapeRegExp = value => String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const tokens = this.emoticonMap
            .map(([token]) => token)
            .sort((a, b) => b.length - a.length)
            .map(escapeRegExp)
            .join('|');
        const lookup = new Map(this.emoticonMap.map(([token, emoji]) => [token.toLowerCase(), emoji]));
        const pattern = new RegExp(`(^|[\\s([{])(${tokens})(?=$|[\\s)\\]},.!?;])`, 'g');
        normalized = normalized.replace(pattern, (match, prefix, token) => prefix + (lookup.get(String(token).toLowerCase()) || token));
        return normalized;
    },

    addMessage(message) {
        if (!message || !this.selected) return;
        if (this.selected.scope === 'channel' && parseInt(message.channel_id || 0, 10) !== this.selected.id) return;
        if (this.selected.scope === 'direct' && parseInt(message.thread_id || 0, 10) !== this.selected.id) return;
        if (this.messages.some(existing => parseInt(existing.id, 10) === parseInt(message.id, 10))) return;
        this.messages.push(message);
        this.lastMessageId = Math.max(this.lastMessageId, parseInt(message.id, 10));
        this.renderMessages();
        this.markSelectedRead();
    },

    async markSelectedRead() {
        if (!this.selected || !this.lastMessageId) return;
        const payload = this.selected.scope === 'channel'
            ? { scope: 'channel', channel_id: this.selected.id, last_read_message_id: this.lastMessageId }
            : { scope: 'direct', thread_id: this.selected.id, last_read_message_id: this.lastMessageId };
        try {
            await API.post('/chat/read', payload);
            await Notifications.refreshSummary({ silent: true });
        } catch (e) {}
    },

    connectSocket() {
        if (!('WebSocket' in window)) {
            $('#chat-connection-state').removeClass().addClass('badge text-bg-warning').text('Polling');
            return;
        }
        const token = API._getItem('andrea_access_token');
        if (!token) return;
        this.socketManuallyClosed = false;
        this.socketAuthed = false;
        const scheme = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
        this.socket = new WebSocket(`${scheme}//${window.location.host}/ws/chat`);
        this.socket.addEventListener('open', () => {
            $('#chat-connection-state').removeClass().addClass('badge text-bg-success').text('Live');
            this.socket.send(JSON.stringify({ type: 'auth', token }));
        });
        this.socket.addEventListener('message', (event) => this.handleSocketMessage(event));
        this.socket.addEventListener('close', () => {
            if (this.socketManuallyClosed) return;
            $('#chat-connection-state').removeClass().addClass('badge text-bg-warning').text('Reconnecting');
            this.reconnectTimer = window.setTimeout(() => this.connectSocket(), 5000);
        });
        this.socket.addEventListener('error', () => {
            $('#chat-connection-state').removeClass().addClass('badge text-bg-warning').text('Polling');
        });
    },

    handleSocketMessage(event) {
        const payload = JSON.parse(event.data || '{}');
        if (payload.type === 'auth.ok') {
            this.socketAuthed = true;
            $('#chat-connection-state').removeClass().addClass('badge text-bg-success').text('Live');
        } else if (payload.type === 'chat.channel.message.created' || payload.type === 'chat.direct.message.created') {
            this.addMessage(payload.message);
        } else if (payload.type === 'chat.typing') {
            $('#chat-typing').text(payload.agent_name ? `${payload.agent_name} is typing…` : '');
            window.clearTimeout(this.typingTimer);
            this.typingTimer = window.setTimeout(() => $('#chat-typing').text(''), 2500);
        } else if (payload.type === 'auth.failed' || payload.type === 'error') {
            if (payload.type === 'auth.failed') this.socketAuthed = false;
            $('#chat-connection-state').removeClass().addClass('badge text-bg-warning').text(payload.message || 'Polling');
        }
    },

    sendTyping() {
        if (!this.socket || this.socket.readyState !== WebSocket.OPEN || !this.selected) return;
        const payload = this.selected.scope === 'channel'
            ? { type: 'chat.typing', scope: 'channel', channel_id: this.selected.id }
            : { type: 'chat.typing', scope: 'direct', thread_id: this.selected.id };
        this.socket.send(JSON.stringify(payload));
    },

    startPolling() {
        this.pollTimer = window.setInterval(async () => {
            if (!this.selected || !this.lastMessageId) return;
            try {
                await this.loadMessages(this.lastMessageId);
            } catch (e) {}
        }, 15000);
    },
};
