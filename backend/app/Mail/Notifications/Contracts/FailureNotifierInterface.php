<?php

namespace BitApps\SMTP\Mail\Notifications\Contracts;

use BitApps\SMTP\Mail\Connections\Connection;
use WP_Error;

interface FailureNotifierInterface
{
    public function notifyFailure(WP_Error $error, ?Connection $connection = null): void;

    public function notifySuccess(): void;
}
