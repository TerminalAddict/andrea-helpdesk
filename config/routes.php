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
use Andrea\Helpdesk\Reports\ReportController;
use Andrea\Helpdesk\KnowledgeBase\KbController;

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
    ['POST', '/api/portal/auth/set-password',       PortalAuthController::class,'setPassword',     ['auth:customer']],

    // ── Tickets ──────────────────────────────────────────────────────────────
    ['GET',    '/api/tickets',                              TicketController::class, 'index',           ['auth:agent']],
    ['POST',   '/api/tickets',                              TicketController::class, 'store',           ['auth:agent']],
    ['GET',    '/api/tickets/:id',                          TicketController::class, 'show',            ['auth:agent']],
    ['PUT',    '/api/tickets/:id',                          TicketController::class, 'update',          ['auth:agent']],
    ['DELETE', '/api/tickets/:id',                          TicketController::class, 'destroy',         ['auth:agent', 'permission:can_delete_tickets']],
    ['POST',   '/api/tickets/:id/assign',                   TicketController::class, 'assign',          ['auth:agent']],
    ['POST',   '/api/tickets/:id/status',                   TicketController::class, 'status',          ['auth:agent']],
    ['POST',   '/api/tickets/:id/merge',                    TicketController::class, 'merge',           ['auth:agent']],
    ['POST',   '/api/tickets/:id/relate',                   TicketController::class, 'relate',          ['auth:agent']],
    ['DELETE', '/api/tickets/:id/relate/:related_id',       TicketController::class, 'unrelate',        ['auth:agent']],
    ['POST',   '/api/tickets/:id/spawn',                    TicketController::class, 'spawn',           ['auth:agent']],
    ['POST',   '/api/tickets/:id/to-kb',                    TicketController::class, 'toKb',            ['auth:agent']],
    ['GET',    '/api/tickets/:id/participants',              TicketController::class, 'participants',    ['auth:agent']],
    ['POST',   '/api/tickets/:id/participants',              TicketController::class, 'addParticipant',  ['auth:agent']],
    ['DELETE', '/api/tickets/:id/participants/:participant_id', TicketController::class, 'removeParticipant', ['auth:agent']],
    ['POST',   '/api/tickets/:id/tags',                     TicketController::class, 'addTags',         ['auth:agent']],
    ['DELETE', '/api/tickets/:id/tags/:tag_id',             TicketController::class, 'removeTag',       ['auth:agent']],

    // ── Replies ───────────────────────────────────────────────────────────────
    ['GET',  '/api/tickets/:id/replies',     ReplyController::class, 'index', ['auth:agent']],
    ['POST', '/api/tickets/:id/replies',     ReplyController::class, 'store', ['auth:agent']],

    // ── Attachments ───────────────────────────────────────────────────────────
    ['POST',   '/api/tickets/:id/attachments', AttachmentController::class, 'store',   ['auth:agent']],
    ['DELETE', '/api/attachments/:id',          AttachmentController::class, 'destroy', ['auth:agent']],

    // ── Tags ─────────────────────────────────────────────────────────────────
    ['GET',  '/api/tags', TagController::class, 'index', ['auth:agent']],
    ['POST', '/api/tags', TagController::class, 'store', ['auth:agent']],

    // ── Customers ─────────────────────────────────────────────────────────────
    ['GET',    '/api/customers',                    CustomerController::class, 'index',       ['auth:agent']],
    ['POST',   '/api/customers',                    CustomerController::class, 'store',       ['auth:agent']],
    ['GET',    '/api/customers/:id',                CustomerController::class, 'show',        ['auth:agent']],
    ['PUT',    '/api/customers/:id',                CustomerController::class, 'update',      ['auth:agent']],
    ['DELETE', '/api/customers/:id',                CustomerController::class, 'destroy',     ['role:admin']],
    ['GET',    '/api/customers/:id/tickets',        CustomerController::class, 'tickets',     ['auth:agent']],
    ['POST',   '/api/customers/:id/portal-invite',  CustomerController::class, 'portalInvite',['role:admin']],

    // ── Agents (admin only) ───────────────────────────────────────────────────
    ['GET',  '/api/agents',                   AgentController::class, 'index',         ['role:admin']],
    ['POST', '/api/agents',                   AgentController::class, 'store',         ['role:admin']],
    ['GET',  '/api/agents/:id',               AgentController::class, 'show',          ['role:admin']],
    ['PUT',  '/api/agents/:id',               AgentController::class, 'update',        ['role:admin']],
    ['POST', '/api/agents/:id/deactivate',    AgentController::class, 'deactivate',    ['role:admin']],
    ['POST', '/api/agents/:id/activate',      AgentController::class, 'activate',      ['role:admin']],
    ['POST', '/api/agents/:id/reset-password',AgentController::class, 'resetPassword', ['role:admin']],

    // ── Settings (admin only) ─────────────────────────────────────────────────
    ['GET',  '/api/admin/settings',             SettingsController::class, 'index',     ['role:admin']],
    ['PUT',  '/api/admin/settings',             SettingsController::class, 'update',    ['role:admin']],
    ['POST', '/api/admin/settings/test-smtp',   SettingsController::class, 'testSmtp',  ['role:admin']],
    ['POST', '/api/admin/settings/test-imap',   SettingsController::class, 'testImap',  ['role:admin']],
    ['POST', '/api/admin/settings/test-slack',  SettingsController::class, 'testSlack', ['role:admin']],

    // ── Reports ───────────────────────────────────────────────────────────────
    ['GET', '/api/reports/summary',       ReportController::class, 'summary',     ['auth:agent', 'permission:can_view_reports']],
    ['GET', '/api/reports/by-agent',      ReportController::class, 'byAgent',     ['auth:agent', 'permission:can_view_reports']],
    ['GET', '/api/reports/by-status',     ReportController::class, 'byStatus',    ['auth:agent', 'permission:can_view_reports']],
    ['GET', '/api/reports/time-to-close', ReportController::class, 'timeToClose', ['auth:agent', 'permission:can_view_reports']],
    ['GET', '/api/reports/volume',        ReportController::class, 'volume',      ['auth:agent', 'permission:can_view_reports']],

    // ── Knowledge Base (GET routes are public) ────────────────────────────────
    ['GET',  '/api/kb/categories',              KbController::class, 'categories',     []],
    ['POST', '/api/kb/categories',              KbController::class, 'storeCategory',  ['role:admin']],
    ['PUT',  '/api/kb/categories/:id',          KbController::class, 'updateCategory', ['role:admin']],
    ['DELETE','/api/kb/categories/:id',         KbController::class, 'destroyCategory',['role:admin']],
    ['GET',  '/api/kb/articles',                KbController::class, 'index',          []],
    ['POST', '/api/kb/articles',                KbController::class, 'store',          ['auth:agent']],
    ['GET',  '/api/kb/articles/:slug',          KbController::class, 'show',           []],
    ['PUT',  '/api/kb/articles/:id',            KbController::class, 'update',         ['auth:agent']],
    ['POST', '/api/kb/articles/:id/publish',    KbController::class, 'publish',        ['role:admin']],
    ['DELETE','/api/kb/articles/:id',           KbController::class, 'destroy',        ['role:admin']],

    // ── Customer Portal ───────────────────────────────────────────────────────
    ['GET',  '/api/portal/tickets',                     PortalController::class, 'index',      ['auth:customer']],
    ['GET',  '/api/portal/tickets/:id',                 PortalController::class, 'show',       ['auth:customer']],
    ['POST', '/api/portal/tickets/:id/replies',         PortalController::class, 'reply',      ['auth:customer']],
    ['POST', '/api/portal/tickets/:id/attachments',     PortalController::class, 'attachment', ['auth:customer']],
];
