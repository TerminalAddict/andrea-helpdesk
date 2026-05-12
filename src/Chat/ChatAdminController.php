<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Chat;

use Andrea\Helpdesk\Core\Request;
use Andrea\Helpdesk\Core\Response;

class ChatAdminController
{
    private ChatService $chat;

    public function __construct()
    {
        $this->chat = new ChatService();
    }

    public function channels(Request $request): void
    {
        Response::success(['channels' => $this->chat->listAllChannels()]);
    }

    public function createChannel(Request $request): void
    {
        Response::created([
            'channel' => $this->chat->createChannel($request->body, (int)$request->agent->id),
        ], 'Chat channel created');
    }

    public function updateChannel(Request $request, array $params): void
    {
        Response::success([
            'channel' => $this->chat->updateChannel((int)$params['id'], $request->body, (int)$request->agent->id),
        ], 'Chat channel updated');
    }

    public function deactivateChannel(Request $request, array $params): void
    {
        $this->chat->deactivateChannel((int)$params['id']);
        Response::success(null, 'Chat channel deactivated');
    }

    public function deleteChannel(Request $request, array $params): void
    {
        $this->chat->deleteChannel((int)$params['id']);
        Response::success(null, 'Chat channel deleted');
    }

    public function directThreads(Request $request): void
    {
        Response::success(['threads' => $this->chat->adminDirectThreads()]);
    }

    public function directMessages(Request $request, array $params): void
    {
        Response::success([
            'messages' => $this->chat->adminDirectMessages(
                (int)$params['thread_id'],
                (int)$request->agent->id,
                $request->ip(),
                (int)$request->input('limit', 50),
                ((int)$request->input('after_id', 0)) ?: null
            ),
        ]);
    }

    public function prunePreview(Request $request): void
    {
        Response::success($this->chat->prunePreview(
            $request->input('scope') !== null ? (string)$request->input('scope') : null,
            $request->input('channel_id') !== null ? (int)$request->input('channel_id') : null
        ));
    }

    public function prune(Request $request): void
    {
        Response::success($this->chat->prune(
            $request->input('scope') !== null ? (string)$request->input('scope') : null,
            $request->input('channel_id') !== null ? (int)$request->input('channel_id') : null,
            (int)$request->agent->id,
            $request->ip()
        ), 'Chat prune completed');
    }

    public function websocketStatus(Request $request): void
    {
        Response::success($this->chat->websocketStatus());
    }

    public function startWebsocket(Request $request): void
    {
        $result = $this->chat->requestWebsocketAction('start', (int)$request->agent->id, $request->ip());
        Response::success($result['status'], $result['message']);
    }

    public function stopWebsocket(Request $request): void
    {
        $result = $this->chat->requestWebsocketAction('stop', (int)$request->agent->id, $request->ip());
        Response::success($result['status'], $result['message']);
    }

    public function restartWebsocket(Request $request): void
    {
        $result = $this->chat->requestWebsocketAction('restart', (int)$request->agent->id, $request->ip());
        Response::success($result['status'], $result['message']);
    }

    public function websocketSettings(Request $request): void
    {
        Response::success($this->chat->updateWebsocketSettings($request->body), 'WebSocket settings saved');
    }
}
