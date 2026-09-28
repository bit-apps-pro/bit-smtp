<?php

namespace BitApps\SMTP\Mail\Contracts;

use BitApps\SMTP\Mail\Connections\Connection;

/**
 * Provider-side delivery webhook provisioning via API.
 */
interface WebhookProvisionerInterface
{
    /**
     * Create or reuse the provider-side webhook idempotently; returns at least ['created' => bool].
     *
     * @return array<string,mixed>
     */
    public function ensure(Connection $connection): array;

    /**
     * Best-effort webhook removal by connection URL; idempotent, never throws.
     */
    public function deregister(Connection $connection): void;
}
