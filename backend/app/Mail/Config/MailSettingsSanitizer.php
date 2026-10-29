<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Config;

use BitApps\SMTP\Plugin;
use BitApps\SMTP\Support\DebugLog;
use Throwable;

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

    private const ROUTING_FIELDS = ['recipient', 'from', 'subject', 'source_plugin'];

    private const ROUTING_OPERATORS = ['equals', 'contains', 'domain', 'matches'];

    private const ROUTING_DOMAIN_FIELDS = ['recipient', 'from'];

    /**
     * Valid webhook provisioning statuses; all others are dropped.
     */
    private const WEBHOOK_PROVISIONING_STATUSES = ['registered', 'failed', 'unsupported', 'unavailable'];

    /**
     * @param null|callable(string):string[] $secretKeyResolver Maps provider key to secret field keys; defaults to live registry.
     * @param array<string,mixed>            $v2
     *
     * @return array<string,mixed>
     */
    public static function sanitize(array $v2, ?callable $secretKeyResolver = null): array
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
                    $out[$key] = array_map(
                        static function (array $conn) use ($secretKeyResolver): array {
                            return self::sanitizeConnection($conn, $secretKeyResolver);
                        },
                        $conns
                    );

                    break;
                case 'features':
                    $out[$key] = self::sanitizeFeatures($v2[$key] ?? []);

                    break;
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $conn
     *
     * @return array<string,mixed>
     */
    private static function sanitizeConnection(array $conn, ?callable $secretKeyResolver): array
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
            isset($conn['settings']) && \is_array($conn['settings']) ? $conn['settings'] : [],
            self::secretKeysFor($out['provider'], $secretKeyResolver)
        );

        $raw                = isset($conn['credentials']) && \is_array($conn['credentials'])
            ? $conn['credentials']
            : [];
        $out['credentials'] = array_map([self::class, 'sanitizeCredentialEntry'], $raw);

        return $out;
    }

    /**
     * Coerce credential entry to {source,value} shape; scalar malformed entries are normalized.
     *
     * @param mixed $entry
     *
     * @return array{source:string,value:string}
     */
    private static function sanitizeCredentialEntry($entry): array
    {
        if (!\is_array($entry)) {
            return [
                'source' => 'database',
                'value'  => \is_scalar($entry) ? (string) $entry : '',
            ];
        }

        return [
            'source' => isset($entry['source']) ? trim((string) $entry['source']) : '',
            'value'  => isset($entry['value']) ? (string) $entry['value'] : '',
        ];
    }

    /**
     * Sanitize connection settings: coerce SMTP keys, keep token expiry as int, pass through provider-specific scalars.
     *
     * @param array<string,mixed> $settings
     * @param string[]            $secretKeys provider keys stripped to prevent secret smuggling
     *
     * @return array<string,mixed>
     */
    private static function sanitizeConnectionSettings(array $settings, array $secretKeys = []): array
    {
        foreach ($secretKeys as $secretKey) {
            unset($settings[$secretKey]);
        }

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

        if (isset($settings['token_expires_at'])) {
            $sanitized['token_expires_at'] = \intval($settings['token_expires_at']);
        }

        if (isset($settings['webhook_provisioning_updated_at'])) {
            $sanitized['webhook_provisioning_updated_at'] = \intval($settings['webhook_provisioning_updated_at']);
        }

        if (isset($settings['webhook_enabled'])) {
            $sanitized['webhook_enabled'] = (bool) filter_var($settings['webhook_enabled'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($settings['webhook_verified'])) {
            $sanitized['webhook_verified'] = (bool) filter_var($settings['webhook_verified'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($settings['webhook_provisioning_status'])) {
            $status = \is_scalar($settings['webhook_provisioning_status'])
                ? trim((string) $settings['webhook_provisioning_status'])
                : '';
            unset($settings['webhook_provisioning_status']);
            if (\in_array($status, self::WEBHOOK_PROVISIONING_STATUSES, true)) {
                $sanitized['webhook_provisioning_status'] = $status;
            }
        }

        foreach ($settings as $key => $value) {
            if (\array_key_exists($key, $sanitized) || !\is_scalar($value)) {
                continue;
            }

            $sanitized[$key] = trim((string) $value);
        }

        return $sanitized;
    }

    /**
     * @param array<string,mixed> $features
     *
     * @return array<string,mixed>
     */
    private static function sanitizeFeatures(array $features): array
    {
        $out = [];

        foreach (self::ALLOWED_FEATURE_KEYS as $key) {
            $value = isset($features[$key]) && \is_array($features[$key]) ? $features[$key] : [];

            if ($key === 'routing') {
                $out[$key] = self::sanitizeRoutingRules($value);
            } elseif ($key === 'alerts') {
                $out[$key] = self::sanitizeAlerts($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Normalize alerts to the channel-list shape and validate each instance's settings by type.
     *
     * @param array<string,mixed> $alerts
     *
     * @return array{enabled:bool,reminder_minutes:int,channels:list<array{id:string,type:string,name:string,enabled:bool,settings:array<string,mixed>}>}
     */
    private static function sanitizeAlerts(array $alerts): array
    {
        $normalized = AlertSettingsNormalizer::normalize($alerts);
        foreach ($normalized['channels'] as $i => $channel) {
            $normalized['channels'][$i]['settings'] = AlertChannelSettings::sanitize($channel['type'], $channel['settings']);
        }

        return $normalized;
    }

    /**
     * Whitelist routing rules: each requires a connection id and at least one valid condition.
     *
     * @param array<int,mixed> $rules
     *
     * @return array<int,array{connectionId:string,conditions:array<int,array{field:string,operator:string,value:string}>}>
     */
    private static function sanitizeRoutingRules(array $rules): array
    {
        $sanitized = [];

        foreach ($rules as $rule) {
            if (!\is_array($rule)) {
                continue;
            }

            $connectionId = self::routingConnectionId($rule);
            if ($connectionId === '') {
                continue;
            }

            $conditions = self::sanitizeRoutingConditions($rule['conditions'] ?? []);
            if ($conditions === []) {
                continue;
            }

            $sanitized[] = [
                'connectionId' => $connectionId,
                'conditions'   => $conditions,
            ];
        }

        return $sanitized;
    }

    /**
     * Accepts both camelCase `connectionId` and snake_case `connection_id` for compatibility.
     *
     * @param array<string,mixed> $rule
     */
    private static function routingConnectionId(array $rule): string
    {
        $id = $rule['connectionId'] ?? $rule['connection_id'] ?? '';

        return \is_scalar($id) ? trim((string) $id) : '';
    }

    /**
     * @param array<int,mixed> $conditions
     *
     * @return array<int,array{field:string,operator:string,value:string}>
     */
    private static function sanitizeRoutingConditions(array $conditions): array
    {
        $sanitized = [];

        foreach ($conditions as $condition) {
            if (!\is_array($condition)) {
                continue;
            }

            $field    = isset($condition['field']) ? trim((string) $condition['field']) : '';
            $operator = isset($condition['operator']) ? trim((string) $condition['operator']) : '';

            if (!\in_array($field, self::ROUTING_FIELDS, true) || !\in_array($operator, self::ROUTING_OPERATORS, true)) {
                continue;
            }

            if ($operator === 'domain' && !\in_array($field, self::ROUTING_DOMAIN_FIELDS, true)) {
                continue;
            }

            $sanitized[] = [
                'field'    => $field,
                'operator' => $operator,
                'value'    => isset($condition['value']) && \is_scalar($condition['value'])
                    ? trim((string) $condition['value'])
                    : '',
            ];
        }

        return $sanitized;
    }

    /**
     * @return string[]
     */
    private static function secretKeysFor(string $provider, ?callable $secretKeyResolver): array
    {
        $resolver = $secretKeyResolver ?? [self::class, 'secretKeysFromRegistry'];

        return $resolver($provider);
    }

    /**
     * Default resolver: looks up live provider registry; returns empty outside bootstrap context.
     *
     * @return string[]
     */
    private static function secretKeysFromRegistry(string $provider): array
    {
        $plugin = Plugin::instance();
        if ($plugin === null) {
            return [];
        }

        try {
            $fields = $plugin->providerRegistry()->get($provider)->fields();
        } catch (Throwable $e) {
            DebugLog::write('secret-key resolution failed: ' . $e->getMessage());

            return [];
        }

        return array_column(
            array_filter($fields, static function (array $field): bool {
                return ($field['secret'] ?? false) === true;
            }),
            'key'
        );
    }
}
