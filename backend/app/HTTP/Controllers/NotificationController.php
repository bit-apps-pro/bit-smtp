<?php

namespace BitApps\SMTP\HTTP\Controllers;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\Deps\BitApps\WPKit\Utils\Capabilities;
use BitApps\SMTP\HTTP\Requests\NotificationTestRequest;
use BitApps\SMTP\Mail\Notifications\NotificationChannelTester;
use BitApps\SMTP\Plugin;

final class NotificationController
{
    public function __construct(private ?NotificationChannelTester $tester = null)
    {
    }

    public function test(NotificationTestRequest $request): Response
    {
        if (!Capabilities::check('manage_options')) {
            return Response::error([])->message(__('Unauthorized', 'bit-smtp'));
        }

        $data    = $request->validated();
        $channel = isset($data['channel']) && \is_scalar($data['channel']) ? (string) $data['channel'] : '';

        if (!$this->tester()->send($channel)) {
            return Response::error([])->message(__('Notification test failed.', 'bit-smtp'));
        }

        return Response::success([])->message(__('Test notification sent.', 'bit-smtp'));
    }

    private function tester(): NotificationChannelTester
    {
        return $this->tester ?? Plugin::instance()->notificationChannelTester();
    }
}
