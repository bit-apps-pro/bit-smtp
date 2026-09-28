<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Connections;

/**
 * Decide if connection is ready to send: OAuth requires token consent, others are sendable on save.
 */
final class ConnectionAuthorization
{
    /**
     * OAuth credential keys proving consent; either one suffices to send.
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
