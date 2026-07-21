<?php

namespace BitApps\SMTP\Mail\Contracts;

interface ProviderInterface
{
    /**
     * Unique machine key, e.g. 'other_smtp'.
     */
    public function key(): string;

    public function label(): string;

    /**
     * 'smtp' or 'api'.
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
     * How this provider carries a delivery-webhook correlation token on send:
     * ['channel' => 'metadata'|'header', 'key' => string], or [] when it can't round-trip one.
     */
    public function tracking(): array;
}
