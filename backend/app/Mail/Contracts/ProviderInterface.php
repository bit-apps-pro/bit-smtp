<?php

namespace BitApps\SMTP\Mail\Contracts;

use BitApps\SMTP\Mail\Providers\ProviderGuide;

/**
 * A mail provider: identity, connection fields, validation, transport, auth, tracking and setup guide.
 *
 * @phpstan-import-type Guide from ProviderGuide
 */
interface ProviderInterface
{
    /**
     * Unique machine key, e.g. 'other_smtp'.
     */
    public function key(): string;

    public function label(): string;

    /**
     * 'smtp', 'api', or 'local'.
     */
    public function kind(): string;

    /**
     * Assoc arrays with keys: key, label, type, required, secret, placeholder, default, options, dependsOn.
     */
    public function fields(): array;

    public function defaults(): array;

    public function validator(): ValidatorInterface;

    public function transport(): TransportInterface;

    /**
     * HTTP auth descriptor for AuthorizationResolver: ['type' => string, 'params' => array].
     */
    public function authConfig(): array;

    /**
     * Webhook correlation token carrier: 'metadata'|'header' channel, or [] if unsupported.
     */
    public function tracking(): array;

    /**
     * Setup guide merged into the REST provider metadata; build it with ProviderGuide::make(), [] for none.
     *
     * @return Guide|array{}
     */
    public function guide(): array;
}
