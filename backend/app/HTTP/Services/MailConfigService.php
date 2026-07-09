<?php

namespace BitApps\SMTP\HTTP\Services;

use BitApps\SMTP\Config;
use BitApps\SMTP\Mail\Config\MailSettings;
use BitApps\SMTP\Mail\Config\MailSettingsMigrator;
use BitApps\SMTP\Mail\Config\MailSettingsSanitizer;
use BitApps\SMTP\Mail\Config\MailSettingsSerializer;

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
