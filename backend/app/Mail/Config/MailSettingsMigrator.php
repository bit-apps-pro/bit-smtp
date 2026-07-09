<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Config;

final class MailSettingsMigrator
{
    public static function migrate(array $stored): array
    {
        if (isset($stored['schema_version']) && (int) $stored['schema_version'] === 2) {
            return $stored;
        }

        if ($stored === []) {
            return self::emptyV2Skeleton();
        }

        return self::migrateLegacy($stored);
    }

    private static function migrateLegacy(array $stored): array
    {
        $stored  = self::resolveTypoKeys($stored);
        $enabled = self::resolveEnabled($stored);
        $connId  = 'conn_' . wp_generate_uuid4();
        $conn    = self::buildConnectionArray($stored, $connId, $enabled);

        return [
            'schema_version'          => 2,
            'enabled'                 => $enabled,
            'default_connection_id'   => $connId,
            'fallback_connection_ids' => [],
            'connections'             => [$conn],
            'features'                => self::emptyFeatures(),
        ];
    }

    private static function resolveTypoKeys(array $stored): array
    {
        if (isset($stored['form_name']) && !isset($stored['from_name'])) {
            $stored['from_name'] = $stored['form_name'];
        }
        unset($stored['form_name']);

        if (isset($stored['form_email_address']) && !isset($stored['from_email_address'])) {
            $stored['from_email_address'] = $stored['form_email_address'];
        }
        unset($stored['form_email_address']);

        return $stored;
    }

    private static function resolveEnabled(array $stored): bool
    {
        if (!isset($stored['status'])) {
            return false;
        }

        return (bool) filter_var($stored['status'], FILTER_VALIDATE_BOOLEAN);
    }

    private static function resolveEncryption(array $stored): string
    {
        if (!\array_key_exists('encryption', $stored)) {
            return 'none';
        }

        if ((string) $stored['encryption'] === '') {
            return 'none';
        }

        return (string) $stored['encryption'];
    }

    private static function buildConnectionArray(array $stored, string $connId, bool $enabled): array
    {
        return [
            'id'           => $connId,
            'provider'     => 'other_smtp',
            'kind'         => 'smtp',
            'name'         => 'Primary SMTP',
            'enabled'      => $enabled,
            'fromEmail'    => (string) ($stored['from_email_address'] ?? ''),
            'fromName'     => (string) ($stored['from_name'] ?? ''),
            'replyToEmail' => (string) ($stored['re_email_address'] ?? ''),
            'settings'     => [
                'host'       => (string) ($stored['smtp_host'] ?? ''),
                'port'       => \intval($stored['port'] ?? 0),
                'encryption' => self::resolveEncryption($stored),
                'auth'       => (bool) filter_var($stored['smtp_auth'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'username'   => (string) ($stored['smtp_user_name'] ?? ''),
                'smtp_debug' => (bool) filter_var($stored['smtp_debug'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ],
            'credentials'  => [
                'password' => [
                    'source' => 'database',
                    'value'  => (string) ($stored['smtp_password'] ?? ''),
                ],
            ],
        ];
    }

    private static function emptyV2Skeleton(): array
    {
        return [
            'schema_version'          => 2,
            'enabled'                 => false,
            'default_connection_id'   => '',
            'fallback_connection_ids' => [],
            'connections'             => [],
            'features'                => self::emptyFeatures(),
        ];
    }

    private static function emptyFeatures(): array
    {
        return [
            'logging'        => [],
            'alerts'         => [],
            'routing'        => [],
            'tracking'       => [],
            'email_controls' => [],
        ];
    }
}
