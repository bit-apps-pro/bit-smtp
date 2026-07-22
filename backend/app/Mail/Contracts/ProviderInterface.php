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

    /**
     * The delivery status a successful send-accept implies for a provider that has no async delivery
     * feed wired (no webhook), as a DeliveryStatus value; null when this provider's real delivery
     * status only arrives out-of-band (webhook/SNS) and must not be inferred from the hand-off.
     */
    public function deliveryStatusOnAccept(): ?string;
}
