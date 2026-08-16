<?php

namespace BitApps\SMTP\Mail\Connections;

use InvalidArgumentException;

class Connection
{
    private string $id;

    private string $provider;

    private string $kind;

    private string $name;

    private bool $enabled;

    private string $fromEmail;

    private string $fromName;

    private string $replyToEmail;

    private array $settings;

    private array $credentials;

    private function __construct(
        string $id,
        string $provider,
        string $kind,
        string $name,
        bool $enabled,
        string $fromEmail,
        string $fromName,
        string $replyToEmail,
        array $settings,
        array $credentials
    ) {
        $this->id           = $id;
        $this->provider     = $provider;
        $this->kind         = $kind;
        $this->name         = $name;
        $this->enabled      = $enabled;
        $this->fromEmail    = $fromEmail;
        $this->fromName     = $fromName;
        $this->replyToEmail = $replyToEmail;
        $this->settings     = $settings;
        $this->credentials  = $credentials;
    }

    public static function fromArray(array $data): self
    {
        foreach (['id', 'provider', 'kind'] as $required) {
            if (!\array_key_exists($required, $data)) {
                throw new InvalidArgumentException("Missing required key: {$required}");
            }
        }

        return new self(
            $data['id'],
            $data['provider'],
            $data['kind'],
            $data['name'] ?? '',
            (bool) ($data['enabled'] ?? false),
            $data['fromEmail']    ?? '',
            $data['fromName']     ?? '',
            $data['replyToEmail'] ?? '',
            $data['settings']     ?? [],
            $data['credentials']  ?? []
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * The identifier stored on a mail-log row's `connection` column and shown in the Logs UI:
     * the connection name, or its provider slug when unnamed. Delivery-webhook correlation scopes
     * on this same value, so both paths must derive it here (never drift).
     */
    public function label(): string
    {
        return $this->name !== '' ? $this->name : $this->provider;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getFromEmail(): string
    {
        return $this->fromEmail;
    }

    public function getFromName(): string
    {
        return $this->fromName;
    }

    public function getReplyToEmail(): string
    {
        return $this->replyToEmail;
    }

    public function getSettings(): array
    {
        return $this->settings;
    }

    public function getCredentials(): array
    {
        return $this->credentials;
    }

    /**
     * @param null|mixed $default
     *
     * @return mixed
     */
    public function setting(string $key, $default = null)
    {
        return $this->settings[$key] ?? $default;
    }

    /**
     * Delivery-webhook tracking is API-only and defaults ON; SMTP connections can never webhook.
     */
    public function isWebhookEnabled(): bool
    {
        return $this->kind === 'api' && (bool) $this->setting('webhook_enabled', true);
    }

    /**
     * Server-minted, immutable per-connection secret forming the webhook URL path segment.
     */
    public function getWebhookSecret(): string
    {
        return (string) $this->setting('webhook_secret', '');
    }

    /**
     * True once the receiver has accepted at least one correlated event for this connection,
     * proving the provider is actually posting. Gates whether delivery status is shown at all.
     */
    public function isWebhookVerified(): bool
    {
        return (bool) $this->setting('webhook_verified', false);
    }

    public function getWebhookLastEventAt(): ?string
    {
        $value = $this->setting('webhook_last_event_at', null);

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    /**
     * Outcome of the most recent auto-provision attempt: 'registered'|'failed'|'unsupported'|
     * 'unavailable', or '' when never attempted.
     */
    public function getWebhookProvisioningStatus(): string
    {
        return (string) $this->setting('webhook_provisioning_status', '');
    }

    /**
     * Short, non-secret explanation for the current provisioning status; '' when none is recorded.
     */
    public function getWebhookProvisioningReason(): string
    {
        return (string) $this->setting('webhook_provisioning_reason', '');
    }

    /**
     * Unix timestamp of the most recent auto-provision attempt, or null when never attempted.
     */
    public function getWebhookProvisioningUpdatedAt(): ?int
    {
        $value = $this->setting('webhook_provisioning_updated_at', null);

        return $value !== null && $value !== '' ? (int) $value : null;
    }

    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'provider'     => $this->provider,
            'kind'         => $this->kind,
            'name'         => $this->name,
            'enabled'      => $this->enabled,
            'fromEmail'    => $this->fromEmail,
            'fromName'     => $this->fromName,
            'replyToEmail' => $this->replyToEmail,
            'settings'     => $this->settings,
            'credentials'  => $this->credentials,
        ];
    }
}
