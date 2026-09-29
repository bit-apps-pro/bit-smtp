<?php

namespace BitApps\SMTP\HTTP\Services;

use BitApps\SMTP\Config;
use BitApps\SMTP\Mail\Config\AlertSettingsNormalizer;
use BitApps\SMTP\Mail\Config\AlertSettingsRevision;
use BitApps\SMTP\Mail\Config\AlertSettingsValidator;
use BitApps\SMTP\Mail\Config\MailSettings;
use BitApps\SMTP\Mail\Config\MailSettingsMigrator;
use BitApps\SMTP\Mail\Config\MailSettingsSanitizer;
use BitApps\SMTP\Mail\Config\MailSettingsSerializer;
use BitApps\SMTP\Mail\Config\MaskedSecretResolver;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Connections\ConnectionAuthorization;
use BitApps\SMTP\Mail\Credentials\CredentialCipher;
use BitApps\SMTP\Mail\Credentials\CredentialValues;
use BitApps\SMTP\Mail\Exceptions\AlertSettingsConflictException;
use BitApps\SMTP\Mail\Exceptions\CredentialCipherException;
use BitApps\SMTP\Mail\Exceptions\InvalidAlertChannelException;
use BitApps\SMTP\Mail\Health\ConnectionHealthStore;
use BitApps\SMTP\Mail\Notifications\AlertDeliveryStatus;
use BitApps\SMTP\Mail\Webhook\WebhookAdapterFactory;
use BitApps\SMTP\Plugin;
use Throwable;

/**
 * Facade over v2 mail-settings (migrate legacy in-memory, writes persist atomically + backup).
 */
class MailConfigService
{
    /**
     * Connection setting holding the OAuth-signed-in account's email (display only; never a secret).
     */
    public const OAUTH_ACCOUNT_SETTING = 'oauth_account';

    private const OPTION = 'options';

    private const LEGACY_BACKUP_OPTION = 'options_v1_backup';

    /**
     * Server-managed webhook keys (stripped per-save, restored from stored connection).
     */
    private const MANAGED_WEBHOOK_SETTING_KEYS = [
        'webhook_verified', 'webhook_last_event_at', 'webhook_signature_enabled', 'webhook_public_key', 'webhook_provisioned_url',
        'webhook_provisioning_status', 'webhook_provisioning_reason', 'webhook_provisioning_updated_at', 'webhook_sns_account_id',
    ];

    private const MANAGED_OAUTH_SETTING_KEYS = [self::OAUTH_ACCOUNT_SETTING];

    /**
     * @var null|MailSettings
     */
    private $settings;

    /**
     * Current settings (cached, read once from options table).
     */
    public function load(): MailSettings
    {
        if ($this->settings === null) {
            $this->settings = $this->hydrate(Config::getOption(self::OPTION, []));
        }

        return $this->settings;
    }

    public function reload(): self
    {
        $this->settings = null;
        $this->load();

        return $this;
    }

    /**
     * Persist full v2 array (backing up legacy config exactly once).
     */
    public function store(MailSettings $settings): bool
    {
        $this->backupLegacyOnce();

        $encrypted = $this->encryptSecrets($settings->toArray());
        $stored    = (bool) Config::updateOption(self::OPTION, $encrypted);

        $this->settings = $settings;

        return $stored;
    }

    /**
     * Persist config from legacy shape (preserve existing password if incoming is empty).
     *
     * @param array<string,mixed> $flat
     */
    public function saveFromLegacy(array $flat): bool
    {
        $v2        = MailSettingsSerializer::fromLegacyShape($flat, $this->load());
        $sanitized = MailSettingsSanitizer::sanitize($v2);
        $stored    = $this->store(MailSettings::fromArray($sanitized));

        $this->reload();

        return $stored;
    }

    /**
     * @return array<string,mixed> Legacy flat shape with PLAINTEXT password for the legacy route.
     */
    public function toLegacyShape(): array
    {
        return MailSettingsSerializer::toLegacyShape($this->load());
    }

    /**
     * V2 API read shape (masked credentials + alerts revision/delivery status).
     *
     * @return array<string,mixed>
     */
    public function apiSettings(): array
    {
        $settings = $this->load();
        $api      = MailSettingsSerializer::toApiShape($settings);

        $api['features']['alerts']['revision'] = AlertSettingsRevision::ofStored($settings);
        $api['features']['alerts']['delivery'] = AlertDeliveryStatus::snapshot(time());

        return $api;
    }

    /**
     * Persist v2 payload (preserve masked creds, reject a stale alerts revision).
     *
     * @param array<string,mixed> $v2
     *
     * @throws AlertSettingsConflictException when the alerts revision is stale
     * @throws InvalidAlertChannelException   when an alert channel id repeats or an enabled one is incomplete
     */
    public function saveSettings(array $v2): bool
    {
        $prior                   = $this->reload()->load();
        $resolved                = MaskedSecretResolver::apply($v2, $prior);
        $resolved['connections'] = $this->withPreservedWebhookFields($resolved['connections'] ?? [], $prior);
        $sanitized               = MailSettingsSanitizer::sanitize($resolved);
        AlertSettingsValidator::assertSaveable($v2, $sanitized['features']['alerts'], $prior);
        $this->store(MailSettings::fromArray($sanitized));
        $this->reload();

        return true;
    }

    /**
     * Upsert connection (new id, preserve masked creds, promote to default if needed).
     *
     * @param array<string,mixed> $connection
     */
    public function saveConnection(array $connection): bool
    {
        return $this->upsertConnection($connection) !== null;
    }

    /**
     * Upsert a connection and return its ID (minted for new, incoming for update) or null if store failed.
     *
     * @param array<string,mixed> $connection
     */
    public function upsertConnection(array $connection): ?string
    {
        return $this->upsertWithManagedSettings($connection, []);
    }

    /**
     * Persist an OAuth consent grant (tokens, expiry, signed-in account) in one write; a grant already stored unchanged counts as saved.
     *
     * @param array<string,mixed> $connection connection payload carrying the fresh tokens and settings[OAUTH_ACCOUNT_SETTING]
     */
    public function saveOAuthGrant(array $connection): bool
    {
        $account = (string) ($connection['settings'][self::OAUTH_ACCOUNT_SETTING] ?? '');

        return $this->upsertWithManagedSettings($connection, [self::OAUTH_ACCOUNT_SETTING => $account]) !== null
            || $this->isOAuthGrantStored($connection, $account);
    }

    /**
     * Write/overwrite connection credential (encrypted at rest like all secrets).
     */
    public function setConnectionCredential(string $connectionId, string $key, string $value): bool
    {
        $data  = $this->load()->toArray();
        $found = false;
        foreach ($data['connections'] as $i => $connection) {
            if (($connection['id'] ?? '') !== $connectionId) {
                continue;
            }

            $credentials = isset($connection['credentials']) && \is_array($connection['credentials'])
                ? $connection['credentials']
                : [];
            $credentials[$key]                      = ['source' => 'manual', 'value' => $value];
            $data['connections'][$i]['credentials'] = $credentials;
            $found                                  = true;

            break;
        }

        if (!$found) {
            return false;
        }

        $stored = $this->store(MailSettings::fromArray(MailSettingsSanitizer::sanitize($data)));
        $this->reload();

        return $stored;
    }

    /**
     * Persist webhook-provisioning output (credentials + server-managed settings, atomic).
     *
     * @param array<string,string> $credentials plaintext values keyed by credential name, tagged 'provisioned'
     * @param array<string,mixed>  $settings    values merged onto the connection's settings
     */
    public function persistConnectionProvisioning(string $connectionId, array $credentials, array $settings): bool
    {
        $data  = $this->load()->toArray();
        $found = false;
        foreach ($data['connections'] as $i => $connection) {
            if (($connection['id'] ?? '') !== $connectionId) {
                continue;
            }

            $existing = isset($connection['credentials']) && \is_array($connection['credentials'])
                ? $connection['credentials']
                : [];
            foreach ($credentials as $key => $value) {
                $existing[$key] = ['source' => 'provisioned', 'value' => (string) $value];
            }

            $data['connections'][$i]['credentials'] = $existing;
            $data['connections'][$i]['settings']    = array_merge($connection['settings'] ?? [], $settings);
            $found                                  = true;

            break;
        }

        if (!$found) {
            return false;
        }

        $stored = $this->store(MailSettings::fromArray(MailSettingsSanitizer::sanitize($data)));
        $this->reload();

        return $stored;
    }

    /**
     * Remove connection (repoint default, remove from fallback/routing, cleanup health/webhook).
     */
    public function deleteConnection(string $id): bool
    {
        $current     = $this->load();
        $removed     = $current->getConnections()->byId($id);
        $data        = $current->toArray();
        $connections = array_values(array_filter(
            $data['connections'],
            static function (array $c) use ($id): bool {
                return $c['id'] !== $id;
            }
        ));

        if ($data['default_connection_id'] === $id) {
            $data['default_connection_id'] = $this->chooseNewDefault($data, $connections);
        }

        $data['fallback_connection_ids'] = array_values(array_filter(
            $data['fallback_connection_ids'] ?? [],
            static function (string $fbId) use ($id): bool {
                return $fbId !== $id;
            }
        ));

        if (isset($data['features']['routing']) && \is_array($data['features']['routing'])) {
            $data['features']['routing'] = array_values(array_filter(
                $data['features']['routing'],
                static function ($rule) use ($id): bool {
                    return !(\is_array($rule) && ($rule['connectionId'] ?? '') === $id);
                }
            ));
        }

        $data['connections'] = $connections;

        $sanitized = MailSettingsSanitizer::sanitize($data);
        $stored    = $this->store(MailSettings::fromArray($sanitized));
        $this->reload();

        if ($stored) {
            $this->forgetConnectionHealth($id);
            if ($removed !== null) {
                $this->deregisterConnectionWebhook($removed);
            }
        }

        return $stored;
    }

    /**
     * Clear OAuth tokens (keep client creds, repoint default, OAuth-only).
     */
    public function disconnectOAuth(string $connectionId): bool
    {
        $current    = $this->load();
        $connection = $current->getConnections()->byId($connectionId);

        if ($connection === null || !$this->requiresOAuthConsent($connection->getProvider())) {
            return false;
        }

        $data = $current->toArray();
        foreach ($data['connections'] as $i => $row) {
            if (($row['id'] ?? '') !== $connectionId) {
                continue;
            }

            foreach (ConnectionAuthorization::OAUTH_TOKEN_KEYS as $tokenKey) {
                unset($data['connections'][$i]['credentials'][$tokenKey]);
            }
            unset($data['connections'][$i]['settings']['token_expires_at']);
            foreach (self::MANAGED_OAUTH_SETTING_KEYS as $managed) {
                unset($data['connections'][$i]['settings'][$managed]);
            }

            break;
        }

        if ($data['default_connection_id'] === $connectionId) {
            $siblings = array_values(array_filter(
                $data['connections'],
                static function (array $c) use ($connectionId): bool {
                    return ($c['id'] ?? '') !== $connectionId;
                }
            ));
            $data['default_connection_id'] = $this->chooseNewDefault($data, $siblings);
        }

        $stored = $this->store(MailSettings::fromArray(MailSettingsSanitizer::sanitize($data)));
        $this->reload();

        return $stored;
    }

    /**
     * Find connection by id, or null.
     */
    public function connectionById(string $id): ?Connection
    {
        return $this->load()->getConnections()->byId($id);
    }

    /**
     * Record webhook live (write-once to avoid read-modify-write race).
     */
    public function markWebhookVerified(string $connId, ?string $eventTime): void
    {
        $current    = $this->load();
        $connection = $current->getConnections()->byId($connId);
        if ($connection === null || $connection->isWebhookVerified()) {
            return;
        }

        $data = $current->toArray();
        foreach ($data['connections'] as $i => $conn) {
            if (($conn['id'] ?? '') !== $connId) {
                continue;
            }

            $data['connections'][$i]['settings']['webhook_verified']      = true;
            $data['connections'][$i]['settings']['webhook_last_event_at'] = $eventTime !== null && $eventTime !== ''
                ? $eventTime
                : gmdate('Y-m-d H:i:s');

            break;
        }

        $this->store(MailSettings::fromArray(MailSettingsSanitizer::sanitize($data)));
        $this->reload();
    }

    public function updateConnectionSettings(string $connId, array $updates): bool
    {
        $current = $this->load();
        if ($current->getConnections()->byId($connId) === null) {
            return false;
        }

        $data = $current->toArray();
        foreach ($data['connections'] as $i => $connection) {
            if (($connection['id'] ?? '') === $connId) {
                $data['connections'][$i]['settings'] = array_merge($connection['settings'] ?? [], $updates);

                break;
            }
        }

        $stored = $this->store(MailSettings::fromArray(MailSettingsSanitizer::sanitize($data)));
        $this->reload();

        return $stored;
    }

    /**
     * Upsert a connection, then overlay server-trusted managed settings that a client payload can never set.
     *
     * @param array<string,mixed> $connection
     * @param array<string,mixed> $managedSettings
     */
    private function upsertWithManagedSettings(array $connection, array $managedSettings): ?string
    {
        $current     = $this->load();
        $data        = $current->toArray();
        $connections = $data['connections'];

        $incomingId      = $connection['id'] ?? '';
        $isNew           = true;
        $savedConnection = [];

        foreach ($connections as $i => $existing) {
            if ($existing['id'] === $incomingId && $incomingId !== '') {
                $resolved     = MaskedSecretResolver::apply(
                    ['connections' => [$connection]],
                    $current
                );
                $resolvedConn    = $resolved['connections'][0];
                $connections[$i] = $resolvedConn;
                $savedConnection = $resolvedConn;
                $isNew           = false;

                break;
            }
        }

        if ($isNew) {
            $connection['id'] = 'conn_' . wp_generate_uuid4();
            $empty            = MailSettings::fromArray([
                'schema_version'          => 2,
                'enabled'                 => false,
                'default_connection_id'   => '',
                'fallback_connection_ids' => [],
                'connections'             => [],
                'features'                => [],
            ]);
            $resolved      = MaskedSecretResolver::apply(
                ['connections' => [$connection]],
                $empty
            );
            $savedConnection = $resolved['connections'][0];
            $connections[]   = $savedConnection;
        }

        $currentDefaultId = $data['default_connection_id'];
        $noDefaultYet     = \count($connections) === 1 || $currentDefaultId === '';
        $sendable         = ConnectionAuthorization::isSendable(
            $savedConnection,
            $this->requiresOAuthConsent((string) ($savedConnection['provider'] ?? ''))
        );
        if ($noDefaultYet && $sendable) {
            $data['default_connection_id'] = $savedConnection['id'];
        }

        $data['connections'] = $this->withManagedSettings(
            $this->withPreservedWebhookFields($connections, $current),
            $savedConnection['id'],
            $managedSettings
        );

        $sanitized = MailSettingsSanitizer::sanitize($data);
        $stored    = $this->store(MailSettings::fromArray($sanitized));
        $this->reload();

        return $stored ? $savedConnection['id'] : null;
    }

    /**
     * Build settings from stored option (migrate legacy, normalize alerts, decrypt).
     *
     * @param mixed $stored
     */
    private function hydrate($stored): MailSettings
    {
        return MailSettings::fromArray($this->decryptSecrets(self::withNormalizedAlerts(MailSettingsMigrator::migrate(\is_array($stored) ? $stored : []))));
    }

    /**
     * Pick new default from candidates (first enabled, else first, else '').
     *
     * @param array<string,mixed>            $data       full settings array, for schema context
     * @param array<int,array<string,mixed>> $candidates connections eligible to become the default
     */
    private function chooseNewDefault(array $data, array $candidates): string
    {
        $connections = MailSettings::fromArray(array_merge($data, ['connections' => $candidates]))->getConnections();
        $newDefault  = $connections->enabled()->first() ?? $connections->first();

        return $newDefault !== null ? $newDefault->getId() : '';
    }

    /**
     * Best-effort removal of deleted connection's health record (swallowed on error).
     */
    private function forgetConnectionHealth(string $id): void
    {
        try {
            Plugin::instance()->app()->make(ConnectionHealthStore::class)->delete($id);
        } catch (Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log -- surface without failing the delete
            error_log('Bit SMTP: failed to clear connection health on delete: ' . $e->getMessage());
        }
    }

    /**
     * Best-effort deregister deleted connection's provider webhook (swallowed on error).
     */
    private function deregisterConnectionWebhook(Connection $connection): void
    {
        try {
            Plugin::instance()->webhookProvisioningService()->deregisterFor($connection);
        } catch (Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log -- surface without failing the delete
            error_log('Bit SMTP: failed to deregister connection webhook on delete: ' . $e->getMessage());
        }
    }

    /**
     * Check OAuth2 auth (degrade to false on unknown/error; prevent save fatals).
     */
    private function requiresOAuthConsent(string $provider): bool
    {
        if ($provider === '') {
            return false;
        }

        try {
            return Plugin::instance()->providerRegistry()->requiresOAuth2($provider);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Mint/preserve server-managed webhook and OAuth-account fields (strip-restore per save, SSRF guard).
     *
     * @param array<int,array<string,mixed>> $connections
     *
     * @return array<int,array<string,mixed>>
     */
    private function withPreservedWebhookFields(array $connections, MailSettings $prior): array
    {
        foreach ($connections as $i => $connection) {
            $settings      = isset($connection['settings']) && \is_array($connection['settings'])
                ? $connection['settings']
                : [];
            $priorConn     = $prior->getConnections()->byId($connection['id'] ?? '');
            $priorSettings = $priorConn !== null ? $priorConn->getSettings() : [];

            foreach ([...self::MANAGED_WEBHOOK_SETTING_KEYS, ...self::MANAGED_OAUTH_SETTING_KEYS] as $managed) {
                unset($settings[$managed]);
                if (\array_key_exists($managed, $priorSettings)) {
                    $settings[$managed] = $priorSettings[$managed];
                }
            }

            if (WebhookAdapterFactory::supportsProvider((string) ($connection['provider'] ?? ''))) {
                $secret = $settings['webhook_secret'] ?? '';
                if ($secret === '') {
                    $secret = $priorSettings['webhook_secret'] ?? '';
                }
                if ($secret === '') {
                    $secret = wp_generate_password(40, false);
                }
                $settings['webhook_secret'] = $secret;
            }

            $connections[$i]['settings'] = $settings;
        }

        return $connections;
    }

    /**
     * Overlay server-trusted managed settings onto one connection, after client-supplied managed keys were stripped.
     *
     * @param array<int,array<string,mixed>> $connections
     * @param array<string,mixed>            $managedSettings
     *
     * @return array<int,array<string,mixed>>
     */
    private function withManagedSettings(array $connections, string $connectionId, array $managedSettings): array
    {
        if ($managedSettings === []) {
            return $connections;
        }

        foreach ($connections as $i => $connection) {
            if (($connection['id'] ?? '') === $connectionId) {
                $connections[$i]['settings'] = array_merge($connection['settings'] ?? [], $managedSettings);
            }
        }

        return $connections;
    }

    /**
     * Whether the stored connection already holds this grant's tokens and account, so a write update_option() reported as a no-op still succeeded.
     *
     * @param array<string,mixed> $connection
     */
    private function isOAuthGrantStored(array $connection, string $account): bool
    {
        $stored = $this->reload()->connectionById((string) ($connection['id'] ?? ''));
        if ($stored === null) {
            return false;
        }

        $tokenKeys = array_flip(ConnectionAuthorization::OAUTH_TOKEN_KEYS);
        $expected  = CredentialValues::flatten(array_intersect_key($connection['credentials'] ?? [], $tokenKeys));
        $actual    = array_intersect_key(CredentialValues::flatten($stored->getCredentials()), $expected);
        ksort($expected);
        ksort($actual);

        return $expected !== []
            && $actual                                                    === $expected
            && (string) $stored->setting(self::OAUTH_ACCOUNT_SETTING, '') === $account;
    }

    /**
     * Snapshot legacy array once before first v2 write (add_option no-op guard).
     */
    private function backupLegacyOnce(): void
    {
        $current = Config::getOption(self::OPTION, []);
        if (\is_array($current) && $current !== [] && !isset($current['schema_version'])) {
            Config::addOption(self::LEGACY_BACKUP_OPTION, $current);
        }
    }

    /**
     * Encrypt every secret before writing (in-memory stays plaintext, persisted is ciphertext).
     *
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    private function encryptSecrets(array $data): array
    {
        return $this->walkSecretValues($data, static function (string $value): string {
            return CredentialCipher::encrypt($value);
        });
    }

    /**
     * Decrypt secrets read from options (so consumers of load() work against plaintext).
     *
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    private function decryptSecrets(array $data): array
    {
        return $this->walkSecretValues($data, [$this, 'decryptSecretValue']);
    }

    /**
     * Degrade unrecoverable secret to '' on decrypt failure (prevent fatal).
     */
    private function decryptSecretValue(string $value): string
    {
        try {
            return CredentialCipher::decrypt($value);
        } catch (CredentialCipherException $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log -- surface a lost credential without fataling the request
            error_log('Bit SMTP: ' . $e->getMessage());

            return '';
        }
    }

    /**
     * Walk credential/secret values applying $transform (tolerates malformed sections).
     *
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    private function walkSecretValues(array $data, callable $transform): array
    {
        if (isset($data['connections']) && \is_array($data['connections'])) {
            foreach ($data['connections'] as $i => $connection) {
                if (!\is_array($connection) || !isset($connection['credentials']) || !\is_array($connection['credentials'])) {
                    continue;
                }

                foreach ($connection['credentials'] as $key => $credential) {
                    if (!\is_array($credential) || !isset($credential['value'])) {
                        continue;
                    }

                    $data['connections'][$i]['credentials'][$key]['value'] = $transform((string) $credential['value']);
                }
            }
        }

        $channels = $data['features']['alerts']['channels'] ?? [];
        foreach (\is_array($channels) ? $channels : [] as $i => $channel) {
            $type = \is_array($channel) ? ($channel['type'] ?? '') : '';
            foreach (MailSettingsSerializer::ALERT_ENCRYPTED_KEYS[$type] ?? [] as $secretKey) {
                if (isset($channel['settings'][$secretKey]) && \is_scalar($channel['settings'][$secretKey])) {
                    $data['features']['alerts']['channels'][$i]['settings'][$secretKey] = $transform(
                        (string) $channel['settings'][$secretKey]
                    );
                }
            }
        }

        return $data;
    }

    /**
     * Normalize alerts blob to channel-list shape before decrypt (consistent shape for load()).
     *
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    private static function withNormalizedAlerts(array $data): array
    {
        if (isset($data['features']) && \is_array($data['features'])) {
            $data['features']['alerts'] = AlertSettingsNormalizer::normalize($data['features']['alerts'] ?? []);
        }

        return $data;
    }
}
