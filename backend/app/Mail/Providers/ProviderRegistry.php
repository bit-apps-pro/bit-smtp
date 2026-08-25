<?php

namespace BitApps\SMTP\Mail\Providers;

use BitApps\SMTP\Mail\Contracts\ProviderInterface;
use BitApps\SMTP\Mail\Exceptions\DuplicateProviderException;
use BitApps\SMTP\Mail\Exceptions\ProviderNotFoundException;
use BitApps\SMTP\Mail\OAuth\OAuthCallbackUrl;
use BitApps\SMTP\Mail\Webhook\WebhookAdapterFactory;
use Throwable;

class ProviderRegistry
{
    /**
     * @var array<string, ProviderInterface>
     */
    private array $providers = [];

    public function register(ProviderInterface $provider): void
    {
        $key = $provider->key();

        if ($this->has($key)) {
            throw DuplicateProviderException::forKey(esc_html($key));
        }

        $this->providers[$key] = $provider;
    }

    public function get(string $key): ProviderInterface
    {
        if (!$this->has($key)) {
            throw ProviderNotFoundException::forKey(esc_html($key));
        }

        return $this->providers[$key];
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    /**
     * Whether the provider authenticates via OAuth2 consent, per its declared authConfig (the single
     * source of truth). Degrades to false for an unknown slug or any resolution error, so callers can
     * branch on OAuth without guarding the lookup themselves.
     */
    public function requiresOAuth2(string $key): bool
    {
        try {
            return $this->has($key)
                && ($this->get($key)->authConfig()['type'] ?? null) === 'oauth2';
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @return ProviderInterface[]
     */
    public function all(): array
    {
        return array_values($this->providers);
    }

    /**
     * @return array<int, array{key: string, label: string, kind: string, supports_webhook: bool, supports_webhook_provisioning: bool, oauth_redirect_url?: string, fields: array}>
     */
    public function metadata(): array
    {
        return array_values(array_map(static function (ProviderInterface $provider): array {
            $metadata = [
                'key'                           => $provider->key(),
                'label'                         => $provider->label(),
                'kind'                          => $provider->kind(),
                'supports_webhook'              => WebhookAdapterFactory::supportsProvider($provider->key()),
                'supports_webhook_provisioning' => WebhookProvisionerFactory::supportsProvider($provider->key()),
                'fields'                        => $provider->fields(),
            ];

            if (($provider->authConfig()['type'] ?? null) === 'oauth2') {
                $metadata['oauth_redirect_url'] = OAuthCallbackUrl::get();
            }

            return $metadata;
        }, $this->providers));
    }
}
