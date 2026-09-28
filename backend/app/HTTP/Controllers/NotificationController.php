<?php

namespace BitApps\SMTP\HTTP\Controllers;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\Deps\BitApps\WPKit\Utils\Capabilities;
use BitApps\SMTP\HTTP\Requests\NotificationTestRequest;
use BitApps\SMTP\Mail\Config\AlertChannelSettings;
use BitApps\SMTP\Mail\Exceptions\InvalidAlertChannelException;
use BitApps\SMTP\Mail\Notifications\NotificationChannelTester;
use BitApps\SMTP\Plugin;

final class NotificationController
{
    public function __construct(private ?NotificationChannelTester $tester = null)
    {
    }

    /**
     * Send a test alert through the submitted (possibly unsaved) channel instance.
     */
    public function test(NotificationTestRequest $request): Response
    {
        if (!Capabilities::check('manage_options')) {
            return Response::error([])->message(__('Unauthorized', 'bit-smtp'));
        }

        $data    = $request->validated();
        $channel = isset($data['channel']) && \is_array($data['channel']) ? $data['channel'] : [];

        try {
            $result = $this->tester()->send(
                AlertChannelSettings::scalarString($channel, 'type'),
                isset($channel['settings']) && \is_array($channel['settings']) ? $channel['settings'] : [],
                AlertChannelSettings::scalarString($channel, 'id')
            );
        } catch (InvalidAlertChannelException $e) {
            return Response::error([])->message($e->getMessage());
        }

        if (!$result->ok()) {
            return Response::error([])->message($result->reason() ?? __('Notification test failed.', 'bit-smtp'));
        }

        return Response::success([])->message(__('Test notification sent.', 'bit-smtp'));
    }

    /**
     * The injected tester, or the container's when none was given.
     */
    private function tester(): NotificationChannelTester
    {
        return $this->tester ?? Plugin::instance()->notificationChannelTester();
    }
}
