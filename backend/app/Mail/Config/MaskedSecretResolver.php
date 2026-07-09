<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Config;

final class MaskedSecretResolver
{
    /**
     * Replace any sentinel values in $incomingV2['connections'] credentials
     * with the stored plaintext from $current, preserving genuine new values.
     *
     * @param array<string,mixed> $incomingV2
     */
    public static function apply(array $incomingV2, MailSettings $current): array
    {
        if (!isset($incomingV2['connections']) || !\is_array($incomingV2['connections'])) {
            return $incomingV2;
        }

        foreach ($incomingV2['connections'] as &$conn) {
            if (!isset($conn['credentials']) || !\is_array($conn['credentials'])) {
                continue;
            }

            $storedConn = $current->getConnections()->byId($conn['id'] ?? '');

            foreach ($conn['credentials'] as $key => &$cred) {
                $storedValue = $storedConn !== null
                    ? ($storedConn->getCredentials()[$key] ?? null)
                    : null;

                $cred = self::resolveCredential($cred, $storedValue);
            }
            unset($cred);
        }
        unset($conn);

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
