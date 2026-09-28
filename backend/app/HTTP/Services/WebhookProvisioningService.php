<?php

declare(strict_types=1);

namespace BitApps\SMTP\HTTP\Services;

use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Providers\ProviderRegistry;
use BitApps\SMTP\Mail\Providers\WebhookProvisionerFactory;
use BitApps\SMTP\Mail\Webhook\WebhookUrl;
use RuntimeException;
use Throwable;

/**
 * Register webhook and persist signature material (keep manual/auto paths in lockstep).
 */
final class WebhookProvisioningService
{
    /**
     * Per-request timeout (prevent slow provider API from hanging admin save).
     */
    private const PROVISION_REQUEST_TIMEOUT_SECONDS = 5;

    private MailConfigService $config;

    private ProviderRegistry $providers;

    private AuthorizationResolver $authResolver;

    private ApiClient $apiClient;

    private WebhookProvisionerFactory $factory;

    public function __construct(
        MailConfigService $config,
        ProviderRegistry $providers,
        AuthorizationResolver $authResolver,
        ApiClient $apiClient,
        WebhookProvisionerFactory $factory
    ) {
        $this->config       = $config;
        $this->providers    = $providers;
        $this->authResolver = $authResolver;
        $this->apiClient    = $apiClient;
        $this->factory      = $factory;
    }

    /**
     * Register/reuse provider webhook and persist signature material (throws on failure).
     *
     * @return array<string,mixed>
     */
    public function ensureFor(Connection $connection): array
    {
        $provider = $this->providers->get($connection->getProvider());
        $auth     = $this->authResolver->resolveFromConfig($provider->authConfig());

        $provisioner = $this->factory->forProvider(
            $connection->getProvider(),
            $this->apiClient->withTimeout(self::PROVISION_REQUEST_TIMEOUT_SECONDS),
            $auth
        );
        if ($provisioner === null) {
            throw new RuntimeException(esc_html__('This provider does not support automatic webhook creation.', 'bit-smtp'));
        }

        $result = $provisioner->ensure($connection);

        $settings    = ['webhook_provisioned_url' => WebhookUrl::forConnection($connection)];
        $credentials = [];

        if (!empty($result['public_key'])) {
            $settings['webhook_signature_enabled'] = true;
            $settings['webhook_public_key']        = (string) $result['public_key'];
            unset($result['public_key']);
        }

        if (!empty($result['signing_secret'])) {
            $settings['webhook_signature_enabled'] = true;
            $credentials['webhook_signing_secret'] = (string) $result['signing_secret'];
            unset($result['signing_secret']);
        }

        if (!$this->config->persistConnectionProvisioning($connection->getId(), $credentials, $settings)) {
            throw new RuntimeException(esc_html__('The webhook was registered but its signature material could not be saved.', 'bit-smtp'));
        }

        return $result;
    }

    /**
     * Best-effort webhook removal (never throws, doesn't block delete).
     */
    public function deregisterFor(Connection $connection): void
    {
        if (!WebhookProvisionerFactory::supportsProvider($connection->getProvider())) {
            return;
        }

        try {
            $provider    = $this->providers->get($connection->getProvider());
            $auth        = $this->authResolver->resolveFromConfig($provider->authConfig());
            $provisioner = $this->factory->forProvider(
                $connection->getProvider(),
                $this->apiClient->withTimeout(self::PROVISION_REQUEST_TIMEOUT_SECONDS),
                $auth
            );
            if ($provisioner !== null) {
                $provisioner->deregister($connection);
            }
        } catch (Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log -- surface without blocking the delete
            error_log('Bit SMTP: webhook deregister failed: ' . $this->redact($e->getMessage(), $connection));
        }
    }

    /**
     * Best-effort provisioning on save (never throws, never fails save; warn on provider error).
     *
     * @return array<string,mixed>
     */
    public function provisionOnSave(Connection $connection): array
    {
        if (!$connection->isWebhookEnabled()) {
            return ['status' => 'skipped'];
        }

        if (!WebhookProvisionerFactory::supportsProvider($connection->getProvider())) {
            $this->recordOutcome($connection, 'unsupported', null);

            return ['status' => 'skipped'];
        }

        try {
            $webhookUrl = WebhookUrl::forConnection($connection);
        } catch (Throwable $e) {
            $this->recordOutcome($connection, 'unavailable', $this->redact($e->getMessage(), $connection));

            return $this->warning($connection, $e);
        }

        try {
            if ((string) $connection->setting('webhook_provisioned_url', '') === $webhookUrl) {
                return ['status' => 'skipped'];
            }

            $result = $this->ensureFor($connection);
            $this->recordOutcome($connection, 'registered', null);

            return ['status' => 'ok', 'created' => (bool) ($result['created'] ?? false)];
        } catch (Throwable $e) {
            $this->recordOutcome($connection, 'failed', $this->redact($e->getMessage(), $connection));

            return $this->warning($connection, $e);
        }
    }

    /**
     * Best-effort persist provisioning outcome for UI health display (swallowed on failure).
     */
    private function recordOutcome(Connection $connection, string $status, ?string $reason): void
    {
        $this->config->persistConnectionProvisioning($connection->getId(), [], [
            'webhook_provisioning_status'     => $status,
            'webhook_provisioning_reason'     => $reason !== null ? mb_substr($reason, 0, 200) : '',
            'webhook_provisioning_updated_at' => time(),
        ]);
    }

    /**
     * @return array{status:string,message:string}
     */
    private function warning(Connection $connection, Throwable $e): array
    {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log -- best-effort provisioning: record the failure without failing the save
        error_log('Bit SMTP webhook auto-provision failed: ' . $this->redact($e->getMessage(), $connection));

        return [
            'status'  => 'warning',
            'message' => __('Connection saved, but automatic webhook registration failed. You can register it from the connection\'s webhook panel.', 'bit-smtp'),
        ];
    }

    /**
     * Redact webhook URL/secret from message before logging (defense-in-depth).
     */
    private function redact(string $message, Connection $connection): string
    {
        $replacements = [];

        try {
            $replacements[WebhookUrl::forConnection($connection)] = '[redacted webhook URL]';
        } catch (Throwable $e) {
        }

        $secret = $connection->getWebhookSecret();
        if ($secret !== '') {
            $replacements[$secret] = '[redacted webhook secret]';
        }

        if ($replacements !== []) {
            $message = strtr($message, $replacements);
        }

        return $this->scrubSecrets($message);
    }

    /**
     * Scrub bearer tokens and API-key-like strings before logging (beyond connection redaction).
     */
    private function scrubSecrets(string $message): string
    {
        $message = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer [redacted]', $message) ?? $message;

        return preg_replace('/[A-Za-z0-9._~-]{20,}/', '[redacted]', $message) ?? $message;
    }
}
