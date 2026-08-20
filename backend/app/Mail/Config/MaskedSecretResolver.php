<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Config;

final class MaskedSecretResolver
{
    /**
     * Replace sentinel values with stored plaintext and preserve stored credential keys omitted
     * from an existing connection's editable payload, while keeping genuine new values.
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
                // No credentials key in the payload: copy stored credentials for existing connections
                // so an update that omits credentials does not silently wipe the stored password.
                // Brand-new connections (id not in $current) stay as-is — there is nothing to restore.
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
                // OAuth access/refresh tokens and other server-managed credentials are not editable
                // fields, so the browser omits them even though it includes other credentials.
                $conn['credentials'] += $storedConn->getCredentials();
            }
        }
        unset($conn);

        return $incomingV2;
    }

    /**
     * @param array<string,mixed> $incomingV2
     *
     * @return array<string,mixed>
     */
    private static function resolveAlertSecrets(array $incomingV2, MailSettings $current): array
    {
        if (!isset($incomingV2['features']['alerts']) || !\is_array($incomingV2['features']['alerts'])) {
            return $incomingV2;
        }

        $storedAlerts = $current->getFeatures()['alerts'] ?? [];

        foreach ($storedAlerts as $channel => $storedChannel) {
            if (!\is_array($storedChannel)) {
                continue;
            }

            if (!\array_key_exists($channel, $incomingV2['features']['alerts'])) {
                $incomingV2['features']['alerts'][$channel] = $storedChannel;

                continue;
            }

            if (!\is_array($incomingV2['features']['alerts'][$channel])) {
                continue;
            }

            // Keep fields a partial editor does not submit (for example Telegram's chat_id), while
            // allowing an explicitly supplied empty value to clear its stored counterpart.
            $incomingV2['features']['alerts'][$channel] += $storedChannel;
        }

        foreach (MailSettingsSerializer::ALERT_SECRET_KEYS as $channel => $secretKeys) {
            if (
                !isset($incomingV2['features']['alerts'][$channel])
                || !\is_array($incomingV2['features']['alerts'][$channel])
            ) {
                continue;
            }

            $storedChannel = isset($storedAlerts[$channel]) && \is_array($storedAlerts[$channel])
                ? $storedAlerts[$channel]
                : [];
            foreach ($secretKeys as $secretKey) {
                $storedValue   = $storedChannel[$secretKey]                              ?? '';
                $incomingValue = $incomingV2['features']['alerts'][$channel][$secretKey] ?? null;

                if ($incomingValue === MailSettingsSerializer::MASK_SENTINEL) {
                    $incomingV2['features']['alerts'][$channel][$secretKey] = (string) $storedValue;
                } elseif ($incomingValue === null && $storedValue !== '') {
                    $incomingV2['features']['alerts'][$channel][$secretKey] = (string) $storedValue;
                }
            }
        }

        return $incomingV2;
    }

    /**
     * Recursively replace sentinel `value` keys with their stored counterpart.
     *
     * @param mixed $incoming
     * @param mixed $stored
     *
     * @return mixed
     */
    private static function resolveCredential($incoming, $stored)
    {
        if (!\is_array($incoming)) {
            // Scalar sentinel blanks to '' — only reachable for unsanitized input; the sanitizer
            // normalises credentials to ['source','value'] before the normal saveSettings path runs.
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
