<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Config;

use BitApps\SMTP\Plugin;
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

    /**
     * @param null|callable(string):string[] $secretKeyResolver Maps a provider key to its secret
     *                                                          field keys; defaults to the live
     *                                                          provider registry when omitted
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
     * @param mixed $entry Normally {source,value}; a raw scalar (malformed client payload) is
     *                     coerced into that shape rather than fataling the request
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
     * Coerce the well-known SMTP keys, keep the numeric token expiry integer-typed, then pass
     * through any other provider-specific scalar setting (SES access_key/region, OAuth client_id,
     * future non-secret fields) as a trimmed string. Array/object values are dropped: settings
     * only ever hold scalars. Credentials are sanitized separately and stay whitelisted.
     *
     * @param string[] $secretKeys provider secret field keys, stripped before the pass-through so a
     *                             client cannot smuggle a secret into `settings` to bypass encryption
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

        // A UNIX timestamp the OAuth refresh compares against time(); keep it integer-typed rather
        // than letting the generic pass-through below stringify it.
        if (isset($settings['token_expires_at'])) {
            $sanitized['token_expires_at'] = \intval($settings['token_expires_at']);
        }

        // Keep the delivery-webhook toggle a real bool; the generic pass-through would stringify
        // false to "" and defeat the enabled check.
        if (isset($settings['webhook_enabled'])) {
            $sanitized['webhook_enabled'] = (bool) filter_var($settings['webhook_enabled'], FILTER_VALIDATE_BOOLEAN);
        }

        foreach ($settings as $key => $value) {
            if (\array_key_exists($key, $sanitized) || !\is_scalar($value)) {
                continue;
            }

            $sanitized[$key] = trim((string) $value);
        }

        return $sanitized;
    }

    private static function sanitizeFeatures(array $features): array
    {
        $out = [];

        foreach (self::ALLOWED_FEATURE_KEYS as $key) {
            $value = isset($features[$key]) && \is_array($features[$key]) ? $features[$key] : [];

            $out[$key] = $key === 'routing' ? self::sanitizeRoutingRules($value) : $value;
        }

        return $out;
    }

    /**
     * Whitelist the smart-routing rules: each surviving rule needs a connection id and at least one
     * condition whose field and operator are known enums. Malformed rules and conditions are dropped.
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

    private static function routingConnectionId(array $rule): string
    {
        // RoutingRule::fromArray reads camelCase `connectionId`; accept the snake_case alias too.
        $id = $rule['connectionId'] ?? $rule['connection_id'] ?? '';

        return \is_scalar($id) ? trim((string) $id) : '';
    }

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
     * Default resolver: looks up the live provider registry. Plugin::instance() is only populated
     * once WordPress has booted, so this degrades to no keys (nothing stripped) outside that context
     * rather than fataling — callers that need the guard enforced pass a resolver explicitly.
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
            // Fail open (a not-yet-registered provider is benign) but stay loud: a known provider
            // whose lookup throws would otherwise let a plaintext secret pass through settings untraced.
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log -- surface without fataling the save
            error_log('BIT SMTP: secret-key resolution failed: ' . $e->getMessage());

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
