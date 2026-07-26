<?php

namespace BitApps\SMTP\Mail\Webhook\Signatures\Contracts;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;

interface WebhookSignatureVerifierInterface
{
    /**
     * Authenticate an inbound provider webhook against the connection's stored signing secret.
     * Returns true when the payload is trusted (or signature checking is not configured for the
     * connection), false when a signature is required but does not validate.
     */
    public function verify(WebhookRequest $request, Connection $connection): bool;
}
