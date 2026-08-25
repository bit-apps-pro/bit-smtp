<?php

namespace BitApps\SMTP\Mail\Notifications;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Notifications\Contracts\FailureNotifierInterface;
use WP_Error;

/**
 * Alerts on send failures through the shared alert channels, guarded by a single-incident streak lock
 * so only the first failure of an episode notifies and a later success resets it.
 */
class FailureNotifier implements FailureNotifierInterface
{
    private AlertChannelDispatcher $dispatcher;

    private FailureNotificationGate $gate;

    public function __construct(AlertChannelDispatcher $dispatcher, FailureNotificationGate $gate)
    {
        $this->dispatcher = $dispatcher;
        $this->gate       = $gate;
    }

    public function notifyFailure(WP_Error $error, ?Connection $connection = null): void
    {
        // With nowhere to send, don't consume the streak lock on a no-op.
        if (!$this->dispatcher->hasEnabledChannel()) {
            return;
        }

        if (!$this->gate->acquire()) {
            return;
        }

        // Verify-before-lock: nothing actually delivered (all channels failed/threw), so release the
        // incident lock or a misconfigured outage would suppress every later alert.
        if (!$this->dispatcher->dispatch(FailureNotification::fromError($error, $connection))) {
            $this->gate->reset();
        }
    }

    public function notifySuccess(): void
    {
        $this->gate->reset();
    }
}
