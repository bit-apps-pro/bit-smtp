<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Connections;

/**
 * Decides whether a connection is ready to send — the gate for promoting it to the default routing
 * target. An OAuth2 provider must have completed consent (a stored refresh or access token, mirroring
 * the Gmail/Microsoft365 validators and OAuth2TokenProvider); every other provider is sendable as
 * soon as it is saved.
 */
final class ConnectionAuthorization
{
    /**
     * OAuth credential keys that prove consent completed; either one present is enough to send.
     * Public so disconnectOAuth clears exactly this set — keeping "cleared ⇒ not sendable" structural.
     *
     * @var string[]
     */
    public const OAUTH_TOKEN_KEYS = ['refresh_token', 'access_token'];

    /**
     * @param array<string,mixed> $connection           raw connection array (id/provider/credentials/...)
     * @param bool                $requiresOAuthConsent whether the connection's provider authenticates via OAuth2
     */
    public static function isSendable(array $connection, bool $requiresOAuthConsent): bool
    {
        if (!$requiresOAuthConsent) {
            return true;
        }

        $credentials = isset($connection['credentials']) && \is_array($connection['credentials'])
            ? $connection['credentials']
            : [];

        foreach (self::OAUTH_TOKEN_KEYS as $key) {
            if (trim((string) ($credentials[$key]['value'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }
}
