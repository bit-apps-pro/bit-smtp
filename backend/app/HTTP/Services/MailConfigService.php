<?php

namespace BitApps\SMTP\HTTP\Services;

use BitApps\SMTP\Config;
use BitApps\SMTP\Mail\Config\MailSettings;
use BitApps\SMTP\Mail\Config\MailSettingsMigrator;
use BitApps\SMTP\Mail\Config\MailSettingsSanitizer;
use BitApps\SMTP\Mail\Config\MailSettingsSerializer;
use BitApps\SMTP\Mail\Config\MaskedSecretResolver;
use BitApps\SMTP\Mail\Connections\Connection;

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
            $this->settings = MailSettings::fromArray(
                MailSettingsMigrator::migrate(Config::getOption(self::OPTION, []))
            );
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

        $stored = (bool) Config::updateOption(self::OPTION, $settings->toArray());

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
        $resolved  = MaskedSecretResolver::apply($v2, $this->load());
        $sanitized = MailSettingsSanitizer::sanitize($resolved);
        $stored    = $this->store(MailSettings::fromArray($sanitized));
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
        $current     = $this->load();
        $data        = $current->toArray();
        $connections = $data['connections'];

        $incomingId = $connection['id'] ?? '';
        $isNew      = true;

        foreach ($connections as $i => $existing) {
            if ($existing['id'] === $incomingId && $incomingId !== '') {
                // Replace in-place, preserving masked credentials from the stored version.
                $resolved          = MaskedSecretResolver::apply(
                    ['connections' => [$connection]],
                    $current
                );
                $connections[$i]   = $resolved['connections'][0];
                $isNew             = false;

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

        $data['connections'] = $connections;

        $sanitized = MailSettingsSanitizer::sanitize($data);
        $stored    = $this->store(MailSettings::fromArray($sanitized));
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
}
