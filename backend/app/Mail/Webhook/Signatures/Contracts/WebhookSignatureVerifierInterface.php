<?php

namespace BitApps\SMTP\Mail\Webhook\Signatures\Contracts;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;

interface WebhookSignatureVerifierInterface
{
    /**
     * Authenticate webhook against connection's signing secret; true if trusted or checking not configured, false if invalid.
     */
    public function verify(WebhookRequest $request, Connection $connection): bool;
}
