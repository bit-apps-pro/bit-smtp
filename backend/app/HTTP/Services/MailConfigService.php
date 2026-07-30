<?php

namespace BitApps\SMTP\HTTP\Services;

use BitApps\SMTP\Config;
use BitApps\SMTP\Mail\Config\MailSettings;
use BitApps\SMTP\Mail\Config\MailSettingsMigrator;
use BitApps\SMTP\Mail\Config\MailSettingsSanitizer;
use BitApps\SMTP\Mail\Config\MailSettingsSerializer;
use BitApps\SMTP\Mail\Config\MaskedSecretResolver;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Credentials\CredentialCipher;
use BitApps\SMTP\Mail\Exceptions\CredentialCipherException;
use BitApps\SMTP\Mail\Webhook\WebhookAdapterFactory;

/**
 * Facade over the v2 mail-settings domain. Reads migrate legacy config in memory only (never
 * rewriting the DB), while writes persist the full v2 array atomically and preserve a one-time
 * backup of any pre-migration legacy config.
 */
class MailConfigService
{
    private const OPTION = 'options';

    private const LEGACY_BACKUP_OPTION = 'options_v1_backup';

    /**
     * @var null|MailSettings
     */
    private $settings;

    public function load(): MailSettings
    {
        if ($this->settings === null) {
            $migrated       = MailSettingsMigrator::migrate(Config::getOption(self::OPTION, []));
            $this->settings = MailSettings::fromArray($this->decryptSecrets($migrated));
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
     * Persist the full v2 array in a single write, backing up any legacy config exactly once first.
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
     * Persist config coming from the legacy flat REST shape, preserving an existing password when
     * the incoming one is empty.
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
     * V2 API read shape: all credential values masked with the sentinel.
     *
     * @return array<string,mixed>
     */
    public function apiSettings(): array
    {
        return MailSettingsSerializer::toApiShape($this->load());
    }

    /**
     * Persist a full v2 payload from the API, preserving any credential values that arrive as the
     * mask sentinel by restoring the stored secret in their place.
     *
     * @param array<string,mixed> $v2
     */
    public function saveSettings(array $v2): bool
    {
        $prior                   = $this->load();
        $resolved                = MaskedSecretResolver::apply($v2, $prior);
        $resolved['connections'] = $this->withPreservedWebhookFields($resolved['connections'] ?? [], $prior);
        $sanitized               = MailSettingsSanitizer::sanitize($resolved);
        $stored                  = $this->store(MailSettings::fromArray($sanitized));
        $this->reload();

        return $stored;
    }

    /**
     * Upsert a single connection. Assigns a new id for new connections, preserves credentials that
     * arrive masked, and promotes to default when it is the only connection.
     *
     * @param array<string,mixed> $connection
     */
    public function saveConnection(array $connection): bool
    {
        return $this->upsertConnection($connection) !== null;
    }

    /**
     * Upsert a single connection and return the resulting connection id (the minted id for a new
     * connection, the incoming id for an update), or null when the store failed.
     *
     * @param array<string,mixed> $connection
     */
    public function upsertConnection(array $connection): ?string
    {
        $current     = $this->load();
        $data        = $current->toArray();
        $connections = $data['connections'];

        $incomingId = $connection['id'] ?? '';
        $isNew      = true;

        foreach ($connections as $i => $existing) {
            if ($existing['id'] === $incomingId && $incomingId !== '') {
                // Replace in-place, preserving masked credentials from the stored version.
                $resolved     = MaskedSecretResolver::apply(
                    ['connections' => [$connection]],
                    $current
                );
                $resolvedConn    = $resolved['connections'][0];
                $connections[$i] = $resolvedConn;
                $isNew           = false;

                break;
            }
        }

        if ($isNew) {
            $connection['id'] = 'conn_' . wp_generate_uuid4();
            // Brand-new connection: sentinel values cannot be preserved — resolve to '' via an
            // empty MailSettings so MaskedSecretResolver correctly blanks them.
            $empty    = MailSettings::fromArray([
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
            $connections[] = $resolved['connections'][0];
        }

        // Promote to default only for new connections (first added, or when no default is set yet).
        $targetId         = $isNew ? $connection['id'] : $incomingId;
        $currentDefaultId = $data['default_connection_id'];
        if ($isNew && (\count($connections) === 1 || $currentDefaultId === '')) {
            $data['default_connection_id'] = $targetId;
        }

        $data['connections'] = $this->withPreservedWebhookFields($connections, $current);

        $sanitized = MailSettingsSanitizer::sanitize($data);
        $stored    = $this->store(MailSettings::fromArray($sanitized));
        $this->reload();

        return $stored ? ($isNew ? $connection['id'] : $incomingId) : null;
    }

    /**
     * Write (or overwrite) a single connection credential, persisting through the same encrypt path
     * as every other secret so the value is stored encrypted and decrypts on load. Used to stash
     * provider-issued signing material (e.g. an HMAC webhook secret) safely at rest.
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
     * Persist webhook-provisioning output — provider-issued credentials (each encrypted at rest via the
     * shared secret path) plus the server-managed settings — onto one connection in a SINGLE atomic
     * write, so signature material and the auto-provision marker can never diverge from a partial write.
     * Returns false when the connection is unknown or the store fails.
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
     * Remove a connection by id. Repoints the default to the first enabled connection (or first,
     * or empty) when the removed connection was the default.
     */
    public function deleteConnection(string $id): bool
    {
        $current     = $this->load();
        $data        = $current->toArray();
        $connections = array_values(array_filter(
            $data['connections'],
            static function (array $c) use ($id): bool {
                return $c['id'] !== $id;
            }
        ));

        // Repoint default when the deleted connection held it.
        if ($data['default_connection_id'] === $id) {
            $remaining  = MailSettings::fromArray(array_merge($data, ['connections' => $connections]));
            $newDefault = $remaining->getConnections()->enabled()->first()
                ?? $remaining->getConnections()->first();
            $data['default_connection_id'] = $newDefault !== null ? $newDefault->getId() : '';
        }

        // Remove from fallback list.
        $data['fallback_connection_ids'] = array_values(array_filter(
            $data['fallback_connection_ids'] ?? [],
            static function (string $fbId) use ($id): bool {
                return $fbId !== $id;
            }
        ));

        $data['connections'] = $connections;

        $sanitized = MailSettingsSanitizer::sanitize($data);
        $stored    = $this->store(MailSettings::fromArray($sanitized));
        $this->reload();

        return $stored;
    }

    /**
     * Find a single connection by id, or null when not found.
     */
    public function connectionById(string $id): ?Connection
    {
        return $this->load()->getConnections()->byId($id);
    }

    /**
     * Receiver-side: record that a connection's webhook is live. Write-once — returns without
     * touching the shared, all-connections options blob once already verified, so concurrent
     * events can't race the read-modify-write nor churn re-encryption on every delivery.
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
     * Mint + preserve the server-managed webhook fields on every save. The incoming payload carries
     * only user-editable settings, so without this an edit would wipe the secret (breaking the URL)
     * and reset webhook_verified (hiding the delivery badge). The secret is minted once for an API
     * connection and thereafter carried forward from the stored connection; SMTP connections get none.
     *
     * @param array<int,array<string,mixed>> $connections
     *
     * @return array<int,array<string,mixed>>
     */
    private function withPreservedWebhookFields(array $connections, MailSettings $prior): array
    {
        foreach ($connections as $i => $connection) {
            if (!WebhookAdapterFactory::supportsProvider((string) ($connection['provider'] ?? ''))) {
                continue;
            }

            $settings      = isset($connection['settings']) && \is_array($connection['settings'])
                ? $connection['settings']
                : [];
            $priorConn     = $prior->getConnections()->byId($connection['id'] ?? '');
            $priorSettings = $priorConn !== null ? $priorConn->getSettings() : [];

            // Server-managed: never trust an incoming value, always restore whatever the stored
            // connection holds (or drop it for a new one). webhook_provisioned_url is included so a
            // client can neither wipe the auto-provision short-circuit marker nor forge it to skip
            // provisioning (which would leave signature verification unconfigured).
            foreach (['webhook_verified', 'webhook_last_event_at', 'webhook_signature_enabled', 'webhook_public_key', 'webhook_provisioned_url'] as $managed) {
                unset($settings[$managed]);
                if (\array_key_exists($managed, $priorSettings)) {
                    $settings[$managed] = $priorSettings[$managed];
                }
            }

            // Treat an explicit '' as absent so re-saving a connection can't rotate a live webhook URL:
            // carry the stored secret forward, minting one only when neither incoming nor stored exists.
            $secret = $settings['webhook_secret'] ?? '';
            if ($secret === '') {
                $secret = $priorSettings['webhook_secret'] ?? '';
            }
            if ($secret === '') {
                $secret = wp_generate_password(40, false);
            }
            $settings['webhook_secret'] = $secret;

            $connections[$i]['settings'] = $settings;
        }

        return $connections;
    }

    /**
     * Snapshot the pre-migration legacy array before the first v2 write. add_option is a no-op when
     * the key already exists, giving a natural once-guard.
     */
    private function backupLegacyOnce(): void
    {
        $current = Config::getOption(self::OPTION, []);
        if (\is_array($current) && $current !== [] && !isset($current['schema_version'])) {
            Config::addOption(self::LEGACY_BACKUP_OPTION, $current);
        }
    }

    /**
     * Encrypt every secret before it is written to the options table. The in-memory MailSettings
     * object built from $data stays plaintext; only the persisted copy is ciphertext.
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
     * Decrypt every secret read from the options table, so every other consumer of load() keeps
     * working against plaintext unchanged.
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
     * A rotated wp_salt (or any other CredentialCipherException) makes a stored secret
     * permanently unrecoverable; degrade that single value to '' rather than fatal the admin screen.
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
     * Walk connection credential values and failure-webhook secrets, applying $transform to every
     * value. Tolerates missing or malformed sections.
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

        foreach (MailSettingsSerializer::ALERT_WEBHOOK_SECRET_KEYS as $secretKey) {
            if (
                isset($data['features']['alerts']['webhook'][$secretKey])
                && \is_scalar($data['features']['alerts']['webhook'][$secretKey])
            ) {
                $data['features']['alerts']['webhook'][$secretKey] = $transform(
                    (string) $data['features']['alerts']['webhook'][$secretKey]
                );
            }
        }

        return $data;
    }
}
