<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Config;

use BitApps\SMTP\Mail\Webhook\WebhookAdapterFactory;

class MailSettingsSerializer
{
    public const MASK_SENTINEL = '********';

    /**
     * Alert secrets by channel. This single map drives API masking, sentinel restoration, and
     * encrypted storage/decryption so adding a channel cannot leave a secret plaintext.
     */
    public const ALERT_SECRET_KEYS = [
        'webhook'  => ['url', 'signing_secret'],
        'slack'    => ['webhook_url'],
        'telegram' => ['bot_token'],
    ];

    public static function toLegacyShape(MailSettings $s): array
    {
        $conn = $s->defaultConnection();

        if ($conn === null) {
            return [
                'status'             => false,
                'from_email_address' => '',
                'from_name'          => '',
                're_email_address'   => '',
                'smtp_host'          => '',
                'encryption'         => 'none',
                'port'               => 0,
                'smtp_auth'          => false,
                'smtp_debug'         => false,
                'smtp_user_name'     => '',
                'smtp_password'      => '',
            ];
        }

        return [
            'status'             => $s->isEnabled() && $conn->isEnabled(),
            'from_email_address' => $conn->getFromEmail(),
            'from_name'          => $conn->getFromName(),
            're_email_address'   => $conn->getReplyToEmail(),
            'smtp_host'          => $conn->setting('host', ''),
            'encryption'         => $conn->setting('encryption', 'none'),
            'port'               => (int) $conn->setting('port', 0),
            'smtp_auth'          => (bool) $conn->setting('auth', false),
            'smtp_debug'         => (bool) $conn->setting('smtp_debug', false),
            'smtp_user_name'     => $conn->setting('username', ''),
            // Return plaintext — legacy frontend needs the real password
            'smtp_password'      => $conn->getCredentials()['password']['value'] ?? '',
        ];
    }

    public static function fromLegacyShape(array $flat, ?MailSettings $current = null): array
    {
        $v2 = MailSettingsMigrator::migrate($flat);

        // Coalesce null/absent to '': a nullable smtp_password must preserve the stored secret,
        // never wipe it, whether the request omits the field or sends it as null.
        $incoming = (string) ($flat['smtp_password'] ?? '');
        if ($incoming === '' && $current !== null) {
            $existingConn = $current->defaultConnection();
            if ($existingConn !== null) {
                $existing = $existingConn->getCredentials()['password']['value'] ?? '';
                if ($existing !== '' && isset($v2['connections'][0])) {
                    $v2['connections'][0]['credentials']['password']['value'] = $existing;
                }
            }
        }

        return $v2;
    }

    public static function toApiShape(MailSettings $s): array
    {
        $data = $s->toArray();

        foreach (self::ALERT_SECRET_KEYS as $channel => $secretKeys) {
            foreach ($secretKeys as $secretKey) {
                if (!empty($data['features']['alerts'][$channel][$secretKey])) {
                    $data['features']['alerts'][$channel][$secretKey] = self::MASK_SENTINEL;
                }
            }
        }

        foreach ($data['connections'] as &$conn) {
            // Derived, read-only display field: the full webhook URL to paste into the provider
            // dashboard. Lives at the connection top level (not settings) so a save round-trip drops
            // it — sanitizeConnection only keeps whitelisted top-level keys, never persisting this.
            if (WebhookAdapterFactory::supportsProvider((string) ($conn['provider'] ?? ''))) {
                $secret              = $conn['settings']['webhook_secret'] ?? '';
                $conn['webhook_url'] = $secret !== ''
                    ? home_url('/bit-smtp/' . $conn['id'] . '/' . $secret)
                    : '';
            }

            if (!isset($conn['credentials']) || !\is_array($conn['credentials'])) {
                continue;
            }
            foreach ($conn['credentials'] as &$cred) {
                $cred = self::maskCredential($cred);
            }
            unset($cred);
        }
        unset($conn);

        return $data;
    }

    /**
     * Mask a single credential entry defensively:
     * - Scalar entries are secrets → replace entirely with the mask.
     * - Array entries: blank any key named 'value' at any depth; recurse into nested arrays.
     *
     * @param mixed $cred
     *
     * @return mixed
     */
    private static function maskCredential($cred)
    {
        if (!\is_array($cred)) {
            return self::MASK_SENTINEL;
        }

        foreach ($cred as $k => &$v) {
            if ($k === 'value') {
                $v = self::MASK_SENTINEL;
            } elseif (\is_array($v)) {
                $v = self::maskCredential($v);
            }
        }
        unset($v);

        return $cred;
    }
}
