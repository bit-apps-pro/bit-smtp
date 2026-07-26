<?php

namespace BitApps\SMTP\Mail\Contracts;

use BitApps\SMTP\Mail\Connections\Connection;

/**
 * A provider that can create its own delivery webhook through its API (rather than the user pasting a
 * URL into a dashboard). Resolved per provider by WebhookProvisionerFactory.
 */
interface WebhookProvisionerInterface
{
    /**
     * Create — or reuse, idempotently — the provider-side delivery webhook for $connection, returning
     * at least ['created' => bool]. Providers may add extras (e.g. 'public_key' for signature setup).
     *
     * @return array<string,mixed>
     */
    public function ensure(Connection $connection): array;
}
