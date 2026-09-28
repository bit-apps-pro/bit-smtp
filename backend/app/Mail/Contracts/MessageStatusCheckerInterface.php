<?php

namespace BitApps\SMTP\Mail\Contracts;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Status\DeliveryStatus;

interface MessageStatusCheckerInterface
{
    /**
     * Best-effort lookup of message delivery outcome; never throws.
     */
    public function check(string $messageId, Connection $connection): ?DeliveryStatus;
}
