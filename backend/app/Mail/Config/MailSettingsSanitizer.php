<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Config;

final class MailSettingsSanitizer
{
    private const ALLOWED_TOP_KEYS = [
        'schema_version',
        'enabled',
        'default_connection_id',
        'fallback_connection_ids',
        'connections',
        'features',
    ];

    private const ALLOWED_FEATURE_KEYS = [
        'logging',
        'alerts',
        'routing',
        'tracking',
        'email_controls',
    ];

    public static function sanitize(array $v2): array
    {
        $out = [];

        foreach (self::ALLOWED_TOP_KEYS as $key) {
            switch ($key) {
                case 'schema_version':
                    $out[$key] = isset($v2[$key]) ? (int) $v2[$key] : 2;

                    break;
                case 'enabled':
                    $out[$key] = isset($v2[$key])
                        ? (bool) filter_var($v2[$key], FILTER_VALIDATE_BOOLEAN)
                        : false;

                    break;
                case 'default_connection_id':
                    $out[$key] = isset($v2[$key]) ? trim((string) $v2[$key]) : '';

                    break;
                case 'fallback_connection_ids':
                    $ids       = isset($v2[$key]) && \is_array($v2[$key]) ? $v2[$key] : [];
                    $out[$key] = array_map(static function ($id): string {
                        return trim((string) $id);
                    }, $ids);

                    break;
                case 'connections':
                    $conns     = isset($v2[$key]) && \is_array($v2[$key]) ? $v2[$key] : [];
                    $out[$key] = array_map([self::class, 'sanitizeConnection'], $conns);

                    break;
                case 'features':
                    $out[$key] = self::sanitizeFeatures($v2[$key] ?? []);

                    break;
            }
        }

        return $out;
    }

    private static function sanitizeConnection(array $conn): array
    {
        $stringFields = ['id', 'provider', 'kind', 'name', 'fromEmail', 'fromName', 'replyToEmail'];
        $out          = [];

        foreach ($stringFields as $field) {
            $out[$field] = isset($conn[$field]) ? trim((string) $conn[$field]) : '';
        }

        $out['enabled'] = isset($conn['enabled'])
            ? (bool) filter_var($conn['enabled'], FILTER_VALIDATE_BOOLEAN)
            : false;

        $out['settings'] = self::sanitizeConnectionSettings(
            isset($conn['settings']) && \is_array($conn['settings']) ? $conn['settings'] : []
        );

        $raw                = isset($conn['credentials']) && \is_array($conn['credentials'])
            ? $conn['credentials']
            : [];
        $out['credentials'] = array_map([self::class, 'sanitizeCredentialEntry'], $raw);

        return $out;
    }

    private static function sanitizeCredentialEntry(array $entry): array
    {
        return [
            'source' => isset($entry['source']) ? trim((string) $entry['source']) : '',
            'value'  => isset($entry['value']) ? (string) $entry['value'] : '',
        ];
    }

    private static function sanitizeConnectionSettings(array $settings): array
    {
        $encryption = isset($settings['encryption']) ? trim((string) $settings['encryption']) : '';

        $sanitized = [
            'host'       => isset($settings['host']) ? trim((string) $settings['host']) : '',
            'port'       => isset($settings['port']) ? \intval($settings['port']) : 0,
            'encryption' => $encryption !== '' ? $encryption : 'none',
            'auth'       => isset($settings['auth'])
                ? (bool) filter_var($settings['auth'], FILTER_VALIDATE_BOOLEAN)
                : false,
            'username'   => isset($settings['username']) ? trim((string) $settings['username']) : '',
            'smtp_debug' => isset($settings['smtp_debug'])
                ? (bool) filter_var($settings['smtp_debug'], FILTER_VALIDATE_BOOLEAN)
                : false,
        ];

        // OAuth2/API connections carry the public client id and the resolved token lifetime here;
        // preserve them so the consent flow and token refresh survive a save round-trip.
        if (isset($settings['client_id'])) {
            $sanitized['client_id'] = trim((string) $settings['client_id']);
        }

        if (isset($settings['token_expires_at'])) {
            $sanitized['token_expires_at'] = \intval($settings['token_expires_at']);
        }

        return $sanitized;
    }

    private static function sanitizeFeatures(array $features): array
    {
        $out = [];

        foreach (self::ALLOWED_FEATURE_KEYS as $key) {
            $out[$key] = isset($features[$key]) && \is_array($features[$key]) ? $features[$key] : [];
        }

        return $out;
    }
}
