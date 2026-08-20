<?php

namespace BitApps\SMTP\Mail\Notifications;

use BitApps\SMTP\Config;

class FailureNotificationGate
{
    private const OPTION = 'failure_notification_active';

    public function acquire(): bool
    {
        global $wpdb;

        $optionName           = self::optionName();
        $incidentId           = wp_generate_uuid4();
        $wasSuppressingErrors = $wpdb->suppress_errors(true);

        try {
            // A duplicate-key error is the ordinary losing result of this unique-key election.
            // Keep it as a plain INSERT (rather than add_option()'s upsert) while avoiding an
            // expected notification race from being emitted as a database error.
            $inserted = $wpdb->insert(
                $wpdb->options,
                [
                    'option_name'  => $optionName,
                    'option_value' => $incidentId,
                    'autoload'     => 'no',
                ],
                ['%s', '%s', '%s']
            );
        } finally {
            $wpdb->suppress_errors($wasSuppressingErrors);
        }

        // Direct database writes bypass WordPress's option-cache maintenance. Clear both the
        // individual value and the negative-cache map after every INSERT attempt: the cache is
        // best-effort only, while the unique option_name row is the coordination authority.
        $this->invalidateOptionCaches($optionName);

        return $inserted === 1;
    }

    public function reset(): void
    {
        global $wpdb;

        $optionName = self::optionName();
        $incidentId = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                $optionName
            )
        );

        if (!\is_string($incidentId) || $incidentId === '') {
            $this->invalidateOptionCaches($optionName);

            return;
        }

        // Pair the exact identifier read from the database with the DELETE. If another request
        // resets this streak and inserts a new one, the old reset sees zero rows and cannot erase
        // the new incident, even when both failures occur in the same second.
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
                $optionName,
                $incidentId
            )
        );

        // Never write a `notoptions` miss here: a new incident may have appeared immediately
        // after the conditional DELETE. Invalidation may cause a cache miss, but cannot hide a
        // database row and keeps the table authoritative under persistent-cache interleavings.
        $this->invalidateOptionCaches($optionName);
    }

    private static function optionName(): string
    {
        return Config::VAR_PREFIX . self::OPTION;
    }

    private function invalidateOptionCaches(string $optionName): void
    {
        wp_cache_delete($optionName, 'options');
        wp_cache_delete('notoptions', 'options');
    }
}
