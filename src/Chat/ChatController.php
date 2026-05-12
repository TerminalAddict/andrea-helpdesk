<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Chat;

use Andrea\Helpdesk\Core\Request;
use Andrea\Helpdesk\Core\Response;

class ChatController
{
    private ChatService $chat;

    public function __construct()
    {
        $this->chat = new ChatService();
    }

    public function channels(Request $request): void
    {
        Response::success(['channels' => $this->chat->listChannelsForAgent((int)$request->agent->id)]);
    }

    public function channelMessages(Request $request, array $params): void
    {
        Response::success([
            'messages' => $this->chat->messagesForChannel(
                (int)$params['channel_id'],
                (int)$request->agent->id,
                (int)$request->input('limit', 50),
                ((int)$request->input('after_id', 0)) ?: null
            ),
        ]);
    }

    public function sendChannelMessage(Request $request, array $params): void
    {
        Response::created([
            'message' => $this->chat->createChannelMessage(
                (int)$params['channel_id'],
                (int)$request->agent->id,
                (string)$request->input('body', '')
            ),
        ], 'Chat message sent');
    }

    public function directThreads(Request $request): void
    {
        Response::success(['threads' => $this->chat->listDirectThreads((int)$request->agent->id)]);
    }

    public function startDirectThread(Request $request): void
    {
        Response::created([
            'thread' => $this->chat->startDirectThread(
                (int)$request->agent->id,
                (int)$request->input('agent_id', 0)
            ),
        ], 'Direct message thread ready');
    }

    public function directMessages(Request $request, array $params): void
    {
        Response::success([
            'messages' => $this->chat->messagesForDirectThread(
                (int)$params['thread_id'],
                (int)$request->agent->id,
                (int)$request->input('limit', 50),
                ((int)$request->input('after_id', 0)) ?: null
            ),
        ]);
    }

    public function sendDirectMessage(Request $request, array $params): void
    {
        Response::created([
            'message' => $this->chat->createDirectMessage(
                (int)$params['thread_id'],
                (int)$request->agent->id,
                (string)$request->input('body', '')
            ),
        ], 'Direct message sent');
    }

    public function markRead(Request $request): void
    {
        $this->chat->markRead(
            (int)$request->agent->id,
            (string)$request->input('scope', 'channel'),
            $request->input('channel_id') !== null ? (int)$request->input('channel_id') : null,
            $request->input('thread_id') !== null ? (int)$request->input('thread_id') : null,
            (int)$request->input('last_read_message_id', 0)
        );
        Response::success(null, 'Chat read marker updated');
    }

    public function events(Request $request): void
    {
        Response::success([
            'events' => $this->chat->eventsAfter(
                (int)$request->agent->id,
                (int)$request->input('after_id', 0),
                (int)$request->input('limit', 100)
            ),
        ]);
    }

    public function agents(Request $request): void
    {
        Response::success(['agents' => $this->chat->activeAgentsForChat((int)$request->agent->id)]);
    }
}
