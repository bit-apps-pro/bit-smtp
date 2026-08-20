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
 * Registers a connection's delivery webhook with its provider and persists the resulting signature
 * material. Centralising the persistence here keeps the manual and the auto-on-save paths in lockstep
 * — either both enable signature verification or neither does; an inbound webhook is never accepted
 * unverified because one path forgot to store the key.
 */
final class WebhookProvisioningService
{
    /**
     * Per-request timeout so a slow provider API can't hang the admin save. A provisioner may issue a
     * few sequential requests, so worst-case wall time is a small multiple of this — kept at/below WP's
     * default for that reason. Provisioning is best-effort: a timed-out call degrades to a warning.
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
     * Register (or reuse) the provider-side webhook and persist its signature material. Throws on any
     * failure; the manual "create webhook" path surfaces the message.
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

        // Persist the signature material (if any) and the short-circuit marker together in one atomic
        // write. webhook_provisioned_url is always recorded; the signature fields depend on the scheme.
        $settings    = ['webhook_provisioned_url' => WebhookUrl::forConnection($connection)];
        $credentials = [];

        // Asymmetric-signature scheme (e.g. SendGrid): the verifier reads the public key from settings.
        if (!empty($result['public_key'])) {
            $settings['webhook_signature_enabled'] = true;
            $settings['webhook_public_key']        = (string) $result['public_key'];
            unset($result['public_key']);
        }

        // HMAC scheme (e.g. Resend): the secret is a credential, so it rides the encrypt-at-rest path.
        if (!empty($result['signing_secret'])) {
            $settings['webhook_signature_enabled'] = true;
            $credentials['webhook_signing_secret'] = (string) $result['signing_secret'];
            unset($result['signing_secret']);
        }

        // Fail loudly if the write does not land: the provider may already be signing, but a verifier
        // with no key fails OPEN (accepts unverified events), so the connection must not be stamped as
        // provisioned unless the material persisted — throwing lets the next save retry instead of
        // locking in an unverified webhook.
        if (!$this->config->persistConnectionProvisioning($connection->getId(), $credentials, $settings)) {
            throw new RuntimeException(esc_html__('The webhook was registered but its signature material could not be saved.', 'bit-smtp'));
        }

        return $result;
    }

    /**
     * Best-effort provisioning triggered by saving a connection. Never throws and never fails the
     * save: a provider error yields a saved connection plus a warning the caller can surface.
     *
     * @return array<string,mixed>
     */
    public function provisionOnSave(Connection $connection): array
    {
        // A user opt-out is not a provisioning failure, so it records no outcome.
        if (!$connection->isWebhookEnabled()) {
            return ['status' => 'skipped'];
        }

        if (!WebhookProvisionerFactory::supportsProvider($connection->getProvider())) {
            $this->recordOutcome($connection, 'unsupported', null);

            return ['status' => 'skipped'];
        }

        // Composed ahead of and separately from the provisioning attempt below, so a non-public/
        // non-HTTPS site (no provider API ever reached) is recorded as 'unavailable' rather than
        // conflated with a provider-side 'failed' outcome.
        try {
            $webhookUrl = WebhookUrl::forConnection($connection);
        } catch (Throwable $e) {
            $this->recordOutcome($connection, 'unavailable', $this->redact($e->getMessage(), $connection));

            return $this->warning($connection, $e);
        }

        try {
            // Already registered for this exact URL: don't re-hit the provider API on every subsequent save.
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
     * Best-effort persistence of the last provisioning attempt's outcome, so the UI can surface
     * webhook health. Failure to persist is swallowed: the outcome is a display aid, not the
     * signature material recordOutcome's caller already fails loudly on.
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
     * Strip the per-connection webhook URL and secret from a message before it is logged — defence in
     * depth beyond the provisioner's own redaction, since the message may originate anywhere.
     */
    private function redact(string $message, Connection $connection): string
    {
        $replacements = [];

        try {
            $replacements[WebhookUrl::forConnection($connection)] = '[redacted webhook URL]';
        } catch (Throwable $e) {
            // No composable URL to redact.
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
     * Scrub common secret shapes from an arbitrary provider error body before it is logged or shown
     * in the admin UI: bearer tokens and long API-key-like runs the connection redaction can't know.
     */
    private function scrubSecrets(string $message): string
    {
        // "Authorization: Bearer <token>" / bare "Bearer <token>" — keep the label, drop the token.
        $message = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer [redacted]', $message) ?? $message;

        // Any remaining long token-alphabet run (API keys, JWTs, base64url secrets); 20+ chars. The
        // class omits `=`/`+`/`/` so a `key=<secret>` separator survives while the value is scrubbed.
        return preg_replace('/[A-Za-z0-9._~-]{20,}/', '[redacted]', $message) ?? $message;
    }
}
