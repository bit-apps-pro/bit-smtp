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
     * @return mixed
     */
    public function setting(string $key, $default = null)
    {
        return $this->settings[$key] ?? $default;
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
