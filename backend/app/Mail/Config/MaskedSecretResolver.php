<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Config;

final class MaskedSecretResolver
{
    /**
     * Replace sentinels with stored plaintext and preserve omitted credential keys.
     *
     * @param array<string,mixed> $incomingV2
     *
     * @return array<string,mixed>
     */
    public static function apply(array $incomingV2, MailSettings $current): array
    {
        $incomingV2 = self::resolveAlertSecrets($incomingV2, $current);

        if (!isset($incomingV2['connections']) || !\is_array($incomingV2['connections'])) {
            return $incomingV2;
        }

        foreach ($incomingV2['connections'] as &$conn) {
            $storedConn = $current->getConnections()->byId($conn['id'] ?? '');

            if (!isset($conn['credentials']) || !\is_array($conn['credentials'])) {
                if ($storedConn !== null) {
                    $conn['credentials'] = $storedConn->getCredentials();
                }

                continue;
            }

            foreach ($conn['credentials'] as $key => &$cred) {
                $storedValue = $storedConn !== null
                    ? ($storedConn->getCredentials()[$key] ?? null)
                    : null;

                $cred = self::resolveCredential($cred, $storedValue);
            }
            unset($cred);

            if ($storedConn !== null) {
                $conn['credentials'] += $storedConn->getCredentials();
            }
        }
        unset($conn);

        return $incomingV2;
    }

    /**
     * Resolve a single alert channel's settings against the stored instance.
     *
     * @param array<string,mixed> $settings
     *
     * @return array<string,mixed>
     */
    public static function resolveAlertChannel(string $id, string $type, array $settings, MailSettings $current): array
    {
        return self::resolveChannelSettings(
            ['id' => $id, 'type' => $type, 'settings' => $settings],
            self::channelsById($current)
        );
    }

    /**
     * Normalize alerts and restore omitted settings and masked secrets from stored instances.
     *
     * @param array<string,mixed> $incomingV2
     *
     * @return array<string,mixed>
     */
    private static function resolveAlertSecrets(array $incomingV2, MailSettings $current): array
    {
        if (!isset($incomingV2['features']['alerts']) || !\is_array($incomingV2['features']['alerts'])) {
            return $incomingV2;
        }

        $alerts = AlertSettingsNormalizer::normalize($incomingV2['features']['alerts']);
        $stored = self::channelsById($current);

        foreach ($alerts['channels'] as $i => $channel) {
            $alerts['channels'][$i]['settings'] = self::resolveChannelSettings($channel, $stored);
        }

        $incomingV2['features']['alerts'] = $alerts;

        return $incomingV2;
    }

    /**
     * Resolve one instance's settings: keep omitted fields and swap masked secrets for stored values.
     *
     * @param array{id:string,type:string,settings:array<string,mixed>}               $channel
     * @param array<string,array{id:string,type:string,settings:array<string,mixed>}> $storedById
     *
     * @return array<string,mixed>
     */
    private static function resolveChannelSettings(array $channel, array $storedById): array
    {
        $match    = $storedById[$channel['id']] ?? null;
        $stored   = $match !== null && $match['type'] === $channel['type'] ? $match['settings'] : [];
        $settings = $channel['settings'] + $stored;
        foreach (MailSettingsSerializer::ALERT_MASKED_KEYS[$channel['type']] ?? [] as $secretKey) {
            if (($settings[$secretKey] ?? null) === MailSettingsSerializer::MASK_SENTINEL) {
                $settings[$secretKey] = (string) ($stored[$secretKey] ?? '');
            }
        }

        return $settings;
    }

    /**
     * Index stored alert channels by id.
     *
     * @return array<string,array{id:string,type:string,name:string,enabled:bool,settings:array<string,mixed>}>
     */
    private static function channelsById(MailSettings $current): array
    {
        return array_column(AlertSettingsNormalizer::normalize($current->getFeatures()['alerts'] ?? [])['channels'], null, 'id');
    }

    /**
     * Recursively replace sentinel value keys with stored counterpart.
     *
     * @param mixed $incoming
     * @param mixed $stored
     *
     * @return mixed
     */
    private static function resolveCredential($incoming, $stored)
    {
        if (!\is_array($incoming)) {
            return $incoming === MailSettingsSerializer::MASK_SENTINEL ? '' : $incoming;
        }

        foreach ($incoming as $k => &$v) {
            if ($k === 'value') {
                if ($v === MailSettingsSerializer::MASK_SENTINEL) {
                    $storedVal = \is_array($stored) ? ($stored['value'] ?? '') : '';
                    $v         = (string) $storedVal;
                }
            } elseif (\is_array($v)) {
                $storedNested = \is_array($stored) ? ($stored[$k] ?? null) : null;
                $v            = self::resolveCredential($v, $storedNested);
            }
        }
        unset($v);

        return $incoming;
    }
}
