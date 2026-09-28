<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Config;

use BitApps\SMTP\Mail\Webhook\WebhookAdapterFactory;

class MailSettingsSerializer
{
    public const MASK_SENTINEL = '********';

    /**
     * Alert settings encrypted at rest by channel type; URLs hidden in DB but shown plaintext to admin.
     */
    public const ALERT_ENCRYPTED_KEYS = [
        'webhook'  => ['url', 'signing_secret'],
        'slack'    => ['webhook_url'],
        'telegram' => ['bot_token'],
        'discord'  => ['webhook_url'],
    ];

    /**
     * Alert settings masked in API reads and restored from storage; URLs absent to remain plaintext.
     */
    public const ALERT_MASKED_KEYS = [
        'webhook'  => ['signing_secret'],
        'slack'    => [],
        'telegram' => ['bot_token'],
        'discord'  => [],
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
            'smtp_password'      => $conn->getCredentials()['password']['value'] ?? '',
        ];
    }

    /**
     * Convert legacy shape to v2; preserve existing password if omitted or null.
     */
    public static function fromLegacyShape(array $flat, ?MailSettings $current = null): array
    {
        $v2 = MailSettingsMigrator::migrate($flat);

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
        $data                       = $s->toArray();
        $data['features']['alerts'] = self::maskAlerts($data['features']['alerts'] ?? []);

        foreach ($data['connections'] as &$conn) {
            if (WebhookAdapterFactory::supportsProvider((string) ($conn['provider'] ?? ''))) {
                $secret              = $conn['settings']['webhook_secret'] ?? '';
                $conn['webhook_url'] = $secret !== ''
                    ? home_url('/bit-smtp/' . $conn['id'] . '/' . $secret)
                    : '';

                $conn['webhook_provisioning'] = self::webhookProvisioning($conn['settings'] ?? []);
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
     * Normalize alerts to channel-list shape and mask configured secrets.
     *
     * @param mixed $alerts
     *
     * @return array<string,mixed>
     */
    private static function maskAlerts($alerts): array
    {
        $alerts = AlertSettingsNormalizer::normalize($alerts);
        foreach ($alerts['channels'] as $i => $channel) {
            foreach (self::ALERT_MASKED_KEYS[$channel['type']] ?? [] as $secretKey) {
                if (!empty($channel['settings'][$secretKey])) {
                    $alerts['channels'][$i]['settings'][$secretKey] = self::MASK_SENTINEL;
                }
            }
        }

        return $alerts;
    }

    /**
     * Assemble webhook-provisioning outcome from flat scalar settings; null when never recorded.
     *
     * @param array<string,mixed> $settings
     *
     * @return null|array{status:string,reason:null|string,updated_at:null|int}
     */
    private static function webhookProvisioning(array $settings): ?array
    {
        $status = (string) ($settings['webhook_provisioning_status'] ?? '');
        if ($status === '') {
            return null;
        }

        $reason    = (string) ($settings['webhook_provisioning_reason'] ?? '');
        $updatedAt = (int) ($settings['webhook_provisioning_updated_at'] ?? 0);

        return [
            'status'     => $status,
            'reason'     => $reason    !== '' ? $reason : null,
            'updated_at' => $updatedAt !== 0 ? $updatedAt : null,
        ];
    }

    /**
     * Mask credential entry: scalars replaced with mask, arrays recurse with 'value' keys blanked.
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
