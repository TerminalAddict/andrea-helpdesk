<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Notifications;

use Andrea\Helpdesk\Core\Request;
use Andrea\Helpdesk\Core\Response;

class PushController
{
    private PushSubscriptionRepository $subscriptions;
    private PushNotificationService $push;

    public function __construct()
    {
        $this->subscriptions = new PushSubscriptionRepository();
        $this->push = new PushNotificationService(null, $this->subscriptions);
    }

    public function config(Request $request): void
    {
        Response::success($this->push->getPublicConfig());
    }

    public function subscribe(Request $request): void
    {
        $subscription = $request->input('subscription', []);
        if (!is_array($subscription)) {
            Response::error('subscription object is required', 422);
            return;
        }

        try {
            $this->subscriptions->save(
                (int)$request->agent->id,
                $subscription,
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            );
            Response::success(null, 'Push subscription saved');
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    public function unsubscribe(Request $request): void
    {
        $endpoint = $request->input('endpoint');
        $this->subscriptions->deleteForAgent(
            (int)$request->agent->id,
            is_string($endpoint) ? $endpoint : null
        );
        Response::success(null, 'Push subscription removed');
    }

    public function test(Request $request): void
    {
        $agentId = (int)$request->agent->id;
        if ($this->subscriptions->countForAgent($agentId) < 1) {
            Response::error('No push subscription is registered for this agent/browser yet', 422);
            return;
        }

        $this->push->sendToAgents([$agentId], [
            'title' => 'Andrea Helpdesk test push',
            'body' => 'Push notifications are working for this account.',
            'link' => '/my-profile/settings/notifications',
            'dedupe_key' => 'test-push:' . $agentId . ':' . time(),
        ]);

        Response::success(null, 'Test push sent');
    }
}
