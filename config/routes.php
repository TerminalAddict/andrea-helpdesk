<?php
declare(strict_types=1);

use Andrea\Helpdesk\Auth\AuthController;
use Andrea\Helpdesk\Portal\PortalAuthController;
use Andrea\Helpdesk\Portal\PortalController;
use Andrea\Helpdesk\Tickets\TicketController;
use Andrea\Helpdesk\Tickets\ReplyController;
use Andrea\Helpdesk\Tickets\AttachmentController;
use Andrea\Helpdesk\Tickets\TagController;
use Andrea\Helpdesk\Customers\CustomerController;
use Andrea\Helpdesk\Agents\AgentController;
use Andrea\Helpdesk\Settings\SettingsController;
use Andrea\Helpdesk\Core\VersionController;
use Andrea\Helpdesk\Core\UpdateController;
use Andrea\Helpdesk\Tickets\CalendarController;
use Andrea\Helpdesk\IMAP\ImapAccountController;
use Andrea\Helpdesk\Reports\ReportController;
use Andrea\Helpdesk\KnowledgeBase\KbController;
use Andrea\Helpdesk\Notifications\NotificationController;
use Andrea\Helpdesk\Notifications\PushController;
use Andrea\Helpdesk\Chat\ChatController;
use Andrea\Helpdesk\Chat\ChatAdminController;

// Route format: [METHOD, /path, ControllerClass, 'method', ['middleware', ...]]
return [

    // ── Auth ────────────────────────────────────────────────────────────────
    ['POST', '/api/auth/login',           AuthController::class, 'login',     []],
    ['POST', '/api/auth/refresh',         AuthController::class, 'refresh',   []],
    ['POST', '/api/auth/logout',          AuthController::class, 'logout',    ['auth:any']],
    ['GET',  '/api/auth/me',              AuthController::class, 'me',        ['auth:any']],
    ['POST', '/api/auth/magic-link',      AuthController::class, 'magicLink', []],

    // ── Portal Auth ──────────────────────────────────────────────────────────
    ['POST', '/api/portal/auth/magic-link',         AuthController::class,      'magicLink',       []],
    ['POST', '/api/portal/auth/verify-magic-link',  PortalAuthController::class,'verifyMagicLink', []],
    ['POST', '/api/portal/auth/set-password',        PortalAuthController::class,'setPassword',     ['auth:customer']],
    ['POST', '/api/portal/auth/change-password',    PortalAuthController::class,'changePassword',  ['auth:customer']],
    ['GET',  '/api/support-form/challenge',         PortalController::class,    'challenge',       []],
    ['POST', '/api/support-form',                   PortalController::class,    'publicCreate',    []],

    // ── Tickets ──────────────────────────────────────────────────────────────
    ['GET',    '/api/tickets',                              TicketController::class, 'index',           ['auth:agent']],
    ['POST',   '/api/tickets',                              TicketController::class, 'store',           ['auth:agent']],
    ['GET',    '/api/tickets/:id',                          TicketController::class, 'show',            ['auth:agent']],
    ['PUT',    '/api/tickets/:id',                          TicketController::class, 'update',          ['auth:agent']],
    ['PUT',    '/api/tickets/:id/replies/:reply_id',        TicketController::class, 'updateReply',      ['auth:agent']],
    ['DELETE', '/api/tickets/:id',                          TicketController::class, 'destroy',         ['auth:agent', 'permission:can_delete_tickets']],
    ['POST',   '/api/tickets/:id/assign',                   TicketController::class, 'assign',          ['auth:agent']],
    ['POST',   '/api/tickets/:id/status',                   TicketController::class, 'status',          ['auth:agent']],
    ['POST',   '/api/tickets/:id/merge',                    TicketController::class, 'merge',           ['auth:agent']],
    ['POST',   '/api/tickets/:id/relations',                 TicketController::class, 'relate',          ['auth:agent']],
    ['DELETE', '/api/tickets/:id/relations/:related_id',    TicketController::class, 'unrelate',        ['auth:agent']],
    ['POST',   '/api/tickets/:id/spawn',                    TicketController::class, 'spawn',           ['auth:agent']],
    ['POST',   '/api/tickets/:id/move-to-kb',                    TicketController::class, 'toKb',            ['auth:agent']],
    ['GET',    '/api/tickets/:id/participants',              TicketController::class, 'participants',    ['auth:agent']],
    ['POST',   '/api/tickets/:id/participants',              TicketController::class, 'addParticipant',  ['auth:agent']],
    ['DELETE', '/api/tickets/:id/participants/:participant_id', TicketController::class, 'removeParticipant', ['auth:agent']],
    ['POST',   '/api/tickets/:id/tags',                     TicketController::class, 'addTags',         ['auth:agent']],
    ['DELETE', '/api/tickets/:id/tags/:tag_id',             TicketController::class, 'removeTag',       ['auth:agent']],

    // ── Calendar ─────────────────────────────────────────────────────────────────
    ['GET', '/api/calendar/token',  CalendarController::class, 'token',  ['auth:agent']],
    ['GET', '/api/calendar/events', CalendarController::class, 'events', ['auth:agent']],
    ['GET', '/api/calendar/ical',   CalendarController::class, 'ical',   []],   // HMAC token auth

    // ── Replies ───────────────────────────────────────────────────────────────
    ['GET',  '/api/tickets/:id/replies',     ReplyController::class, 'index', ['auth:agent']],
    ['POST', '/api/tickets/:id/replies',     ReplyController::class, 'store', ['auth:agent']],

    // ── Attachments ───────────────────────────────────────────────────────────
    ['POST',   '/api/tickets/:id/attachments', AttachmentController::class, 'store',   ['auth:agent']],
    ['DELETE', '/api/attachments/:id',          AttachmentController::class, 'destroy', ['auth:agent']],

    // ── Tags ─────────────────────────────────────────────────────────────────
    ['GET',    '/api/tags',      TagController::class, 'index',   ['auth:agent']],
    ['POST',   '/api/tags',      TagController::class, 'store',   ['auth:agent', 'permission:can_manage_tags']],
    ['PUT',    '/api/tags/:id',  TagController::class, 'update',  ['auth:agent', 'permission:can_manage_tags']],
    ['DELETE', '/api/tags/:id',  TagController::class, 'destroy', ['auth:agent', 'permission:can_manage_tags']],

    // ── Customers ─────────────────────────────────────────────────────────────
    ['GET',    '/api/customers',                    CustomerController::class, 'index',       ['auth:agent']],
    ['POST',   '/api/customers/import',             CustomerController::class, 'import',      ['auth:agent', 'permission:can_edit_customers']],
    ['POST',   '/api/customers',                    CustomerController::class, 'store',       ['auth:agent']],
    ['GET',    '/api/customers/:id',                CustomerController::class, 'show',        ['auth:agent']],
    ['PUT',    '/api/customers/:id',                CustomerController::class, 'update',      ['auth:agent']],
    ['DELETE', '/api/customers/:id',                CustomerController::class, 'destroy',     ['role:admin']],
    ['GET',    '/api/customers/:id/tickets',        CustomerController::class, 'tickets',     ['auth:agent']],
    ['GET',    '/api/customers/:id/replies',        CustomerController::class, 'replies',     ['auth:agent']],
    ['POST',   '/api/customers/:id/portal-invite',   CustomerController::class, 'portalInvite', ['auth:agent', 'permission:can_edit_customers']],
    ['POST',   '/api/customers/:id/set-password',    CustomerController::class, 'setPassword',  ['auth:agent', 'permission:can_edit_customers']],

    // ── Agents ───────────────────────────────────────────────────────────────
    ['PUT',  '/api/agent/profile',            AgentController::class, 'updateProfile', ['auth:agent']],
    ['GET',  '/api/notifications',            NotificationController::class, 'index',         ['auth:agent']],
    ['GET',  '/api/notifications/active',     NotificationController::class, 'active',        ['auth:agent']],
    ['GET',  '/api/notifications/preferences', NotificationController::class, 'preferences', ['auth:agent']],
    ['PUT',  '/api/notifications/preferences', NotificationController::class, 'updatePreferences', ['auth:agent']],
    ['DELETE', '/api/notifications/:id',      NotificationController::class, 'delete',        ['auth:agent']],
    ['POST', '/api/tickets/:id/notifications/opened', NotificationController::class, 'dismissOpenedTicket', ['auth:agent']],
    ['POST', '/api/notifications/check-updates', NotificationController::class, 'checkUpdates', ['role:admin']],
    ['GET',  '/api/push/config',              PushController::class, 'config',      ['auth:agent']],
    ['POST', '/api/push/subscriptions',       PushController::class, 'subscribe',   ['auth:agent']],
    ['DELETE', '/api/push/subscriptions',     PushController::class, 'unsubscribe', ['auth:agent']],
    ['POST', '/api/push/test',                PushController::class, 'test',        ['auth:agent']],
    ['GET',  '/api/chat/channels',            ChatController::class, 'channels',    ['auth:agent']],
    ['GET',  '/api/chat/channels/:channel_id/messages', ChatController::class, 'channelMessages', ['auth:agent']],
    ['POST', '/api/chat/channels/:channel_id/messages', ChatController::class, 'sendChannelMessage', ['auth:agent']],
    ['GET',  '/api/chat/direct',              ChatController::class, 'directThreads', ['auth:agent']],
    ['POST', '/api/chat/direct',              ChatController::class, 'startDirectThread', ['auth:agent']],
    ['GET',  '/api/chat/direct/:thread_id/messages', ChatController::class, 'directMessages', ['auth:agent']],
    ['POST', '/api/chat/direct/:thread_id/messages', ChatController::class, 'sendDirectMessage', ['auth:agent']],
    ['POST', '/api/chat/read',                ChatController::class, 'markRead',    ['auth:agent']],
    ['GET',  '/api/chat/events',              ChatController::class, 'events',      ['auth:agent']],
    ['GET',  '/api/chat/agents',              ChatController::class, 'agents',      ['auth:agent']],
    ['GET',  '/api/agents',                   AgentController::class, 'index',         ['auth:agent']],
    ['POST', '/api/agents',                   AgentController::class, 'store',         ['role:admin']],
    ['GET',  '/api/agents/:id',               AgentController::class, 'show',          ['auth:agent']],
    ['PUT',  '/api/agents/:id',               AgentController::class, 'update',        ['role:admin']],
    ['POST', '/api/agents/:id/deactivate',    AgentController::class, 'deactivate',    ['role:admin']],
    ['POST', '/api/agents/:id/activate',      AgentController::class, 'activate',      ['role:admin']],
    ['POST', '/api/agents/:id/reset-password',AgentController::class, 'resetPassword', ['role:admin']],

    // ── Settings ──────────────────────────────────────────────────────────────
    ['GET',  '/api/settings/public',            SettingsController::class, 'publicSettings', []],
    ['GET',    '/api/admin/imap-accounts',         ImapAccountController::class, 'index',   ['role:admin']],
    ['POST',   '/api/admin/imap-accounts',         ImapAccountController::class, 'store',   ['role:admin']],
    ['PUT',    '/api/admin/imap-accounts/:id',      ImapAccountController::class, 'update',  ['role:admin']],
    ['DELETE', '/api/admin/imap-accounts/:id',      ImapAccountController::class, 'destroy', ['role:admin']],
    ['POST',   '/api/admin/imap-accounts/:id/test',         ImapAccountController::class, 'test',        ['role:admin']],
    ['GET',    '/api/admin/imap-accounts/:id/list-folders', ImapAccountController::class, 'listFolders', ['role:admin']],
    ['POST',   '/api/admin/imap-accounts/:id/poll-now',     ImapAccountController::class, 'pollNow',     ['role:admin']],
    ['POST',   '/api/imap/trigger-poll',            ImapAccountController::class, 'triggerPoll', ['auth:agent']],

    ['GET',  '/api/admin/settings',             SettingsController::class, 'index',     ['role:admin']],
    ['PUT',  '/api/admin/settings',             SettingsController::class, 'update',    ['role:admin']],
    ['POST', '/api/admin/settings/test-smtp',   SettingsController::class, 'testSmtp',  ['role:admin']],
    ['POST', '/api/admin/settings/test-imap',   SettingsController::class, 'testImap',  ['role:admin']],
    ['POST', '/api/admin/settings/test-slack',  SettingsController::class, 'testSlack', ['role:admin']],
    ['GET',  '/api/admin/settings/push-status', SettingsController::class, 'pushStatus', ['role:admin']],
    ['POST', '/api/admin/settings/generate-push-keys', SettingsController::class, 'generatePushKeys', ['role:admin']],
    ['GET',  '/api/admin/chat/channels',              ChatAdminController::class, 'channels', ['role:admin']],
    ['POST', '/api/admin/chat/channels',              ChatAdminController::class, 'createChannel', ['role:admin']],
    ['PUT',  '/api/admin/chat/channels/:id',          ChatAdminController::class, 'updateChannel', ['role:admin']],
    ['POST', '/api/admin/chat/channels/:id/deactivate', ChatAdminController::class, 'deactivateChannel', ['role:admin']],
    ['DELETE', '/api/admin/chat/channels/:id',        ChatAdminController::class, 'deleteChannel', ['role:admin']],
    ['GET',  '/api/admin/chat/direct-threads',        ChatAdminController::class, 'directThreads', ['role:admin']],
    ['GET',  '/api/admin/chat/direct-threads/:thread_id/messages', ChatAdminController::class, 'directMessages', ['role:admin']],
    ['POST', '/api/admin/chat/prune/preview',         ChatAdminController::class, 'prunePreview', ['role:admin']],
    ['POST', '/api/admin/chat/prune',                 ChatAdminController::class, 'prune', ['role:admin']],
    ['GET',  '/api/admin/chat/websocket/status',      ChatAdminController::class, 'websocketStatus', ['role:admin']],
    ['POST', '/api/admin/chat/websocket/start',       ChatAdminController::class, 'startWebsocket', ['role:admin']],
    ['POST', '/api/admin/chat/websocket/stop',        ChatAdminController::class, 'stopWebsocket', ['role:admin']],
    ['POST', '/api/admin/chat/websocket/restart',     ChatAdminController::class, 'restartWebsocket', ['role:admin']],
    ['PUT',  '/api/admin/chat/websocket/settings',    ChatAdminController::class, 'websocketSettings', ['role:admin']],

    // ── Version & updates ─────────────────────────────────────────────────────
    ['GET',  '/api/version',          VersionController::class, 'index',     ['role:admin']],
    ['GET',  '/api/version/latest',   VersionController::class, 'latest',    ['role:admin']],
    ['GET',  '/api/update/preflight', UpdateController::class,  'preflight', ['role:admin']],
    ['POST', '/api/update/run',       UpdateController::class,  'run',       ['role:admin']],

    // ── Reports ───────────────────────────────────────────────────────────────
    ['GET', '/api/reports/snapshot',      ReportController::class, 'snapshot',    ['auth:agent']],
    ['GET', '/api/reports/activity-summary', ReportController::class, 'activitySummary', ['auth:agent', 'permission:can_view_reports']],
    ['GET', '/api/reports/activity-by-agent', ReportController::class, 'activityByAgent', ['auth:agent', 'permission:can_view_reports']],
    ['GET', '/api/reports/activity-volume', ReportController::class, 'activityVolume', ['auth:agent', 'permission:can_view_reports']],
    ['GET', '/api/reports/time-to-close', ReportController::class, 'timeToClose', ['auth:agent', 'permission:can_view_reports']],

    // ── Knowledge Base (GET routes are public) ────────────────────────────────
    ['GET',  '/api/kb/categories',              KbController::class, 'categories',     []],
    ['POST', '/api/kb/categories',              KbController::class, 'storeCategory',  ['auth:agent', 'permission:can_manage_kb']],
    ['PUT',  '/api/kb/categories/:id',          KbController::class, 'updateCategory', ['auth:agent', 'permission:can_manage_kb']],
    ['DELETE','/api/kb/categories/:id',         KbController::class, 'destroyCategory',['auth:agent', 'permission:can_manage_kb']],
    ['GET',  '/api/kb/articles',                KbController::class, 'index',          []],
    ['POST', '/api/kb/articles',                KbController::class, 'store',          ['auth:agent']],
    ['GET',  '/api/kb/articles/:slug',          KbController::class, 'show',           []],
    ['PUT',  '/api/kb/articles/:id',            KbController::class, 'update',         ['auth:agent']],
    ['POST', '/api/kb/articles/:id/publish',    KbController::class, 'publish',        ['role:admin']],
    ['DELETE','/api/kb/articles/:id',           KbController::class, 'destroy',        ['role:admin']],

    // ── Customer Portal ───────────────────────────────────────────────────────
    ['POST', '/api/portal/tickets',                     PortalController::class, 'create',     ['auth:customer']],
    ['GET',  '/api/portal/tickets',                     PortalController::class, 'index',      ['auth:customer']],
    ['GET',  '/api/portal/tickets/:id',                 PortalController::class, 'show',       ['auth:customer']],
    ['POST', '/api/portal/tickets/:id/replies',         PortalController::class, 'reply',      ['auth:customer']],
    ['POST', '/api/portal/tickets/:id/attachments',     PortalController::class, 'attachment', ['auth:customer']],
];
