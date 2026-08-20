<?php

namespace BitApps\SMTP\Mail\Contracts;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Status\DeliveryStatus;

interface MessageStatusCheckerInterface
{
    /**
     * Best-effort lookup of a sent message's real delivery outcome. Returns null when the
     * outcome is indeterminate or the lookup is unsupported/failed — it must never throw.
     */
    public function check(string $messageId, Connection $connection): ?DeliveryStatus;
}
