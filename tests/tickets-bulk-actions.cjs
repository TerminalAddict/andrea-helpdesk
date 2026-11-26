const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

async function scenario({ action = 'resolve', confirm = true, permitted = true, fail = null } = {}) {
    const calls = [], prompts = [], toasts = [];
    const root = {};
    const element = { prop() { return this; }, text() { return this; }, each() { return this; } };
    const context = vm.createContext({
        console, Set,
        $: () => element,
        document: { getElementById: () => root },
        API: {
            isAdmin: () => false, can: () => permitted,
            async post(path, data) {
                calls.push([path, data.status]);
                if (path.includes(`/${fail}/`)) throw new Error('Permission changed');
            },
            async delete(path) { calls.push([path]); },
        },
        App: {
            async confirm(message) { prompts.push(message); return confirm; },
            toast(message) { toasts.push(message); },
        },
    });
    vm.runInContext(fs.readFileSync('public_html/assets/js/views/tickets.js', 'utf8') + '\nglobalThis.view = TicketsView;', context);
    const view = context.view;
    view._view = {};
    view._tickets = [
        { id: 1, ticket_number: 'TEST-1' },
        { id: 2, ticket_number: 'TEST-2', parent_ticket_id: 1 },
        { id: 3, ticket_number: 'TEST-3' },
    ];
    view._selected = new Set([1, 2]);
    view.loadTickets = async () => { view.reloaded = true; };
    await Promise.all([view.runBulkAction(action), view.runBulkAction(action)]);
    assert.equal(calls.length, confirm && permitted ? 2 : 0, 'No duplicate submissions or unselected tickets');
    if (confirm && permitted) {
        assert.equal(view.reloaded, true);
        assert.equal(view._bulkBusy, false);
        if (action === 'delete') {
            assert.deepEqual(calls.map(call => call[0]), ['/tickets/2', '/tickets/1']);
            assert.match(prompts[0], /outside this page/);
        } else {
            assert.deepEqual(calls.map(call => call[1]), ['resolved', 'resolved']);
        }
        if (fail !== null) {
            assert.match(prompts[1], /TEST-2: Permission changed/);
            assert.match(toasts[0], /1 selected ticket/);
        }
    }
    console.log(`PASS: ${action}, confirmed=${confirm}, permitted=${permitted}, fail=${fail}`);
}

(async () => {
    await scenario();
    await scenario({ action: 'delete' });
    await scenario({ confirm: false });
    await scenario({ permitted: false });
    await scenario({ fail: 2 });
})().catch(error => { console.error(error); process.exitCode = 1; });
