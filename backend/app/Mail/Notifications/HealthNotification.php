<?php

namespace BitApps\SMTP\Mail\Notifications;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Health\ConnectionHealth;
use BitApps\SMTP\Mail\Notifications\Contracts\NotificationMessage;

/**
 * A connection-health / OAuth-expiry alert payload. Carries only non-sensitive identity and status
 * (connection id/label/provider, derived status, a sanitized last error, OAuth expiry) — never
 * credentials, tokens, message payloads, or recipients.
 */
final class HealthNotification implements NotificationMessage
{
    public const EVENT_UNHEALTHY = 'connection_unhealthy';

    public const EVENT_RECOVERED = 'connection_recovered';

    public const EVENT_OAUTH_EXPIRING = 'oauth_expiring';

    public const EVENT_OAUTH_EXPIRED = 'oauth_expired';

    /**
     * Cap the surfaced error length; the recorded error is already a short host/auth string, but keep
     * it bounded and single-line so nothing unexpected is ever placed into an alert.
     */
    private const MAX_ERROR_LENGTH = 300;

    private string $event;

    private string $siteName;

    private string $siteUrl;

    private string $generatedAt;

    private string $connectionId;

    private string $connectionLabel;

    private string $provider;

    private string $status;

    private ?string $lastError;

    private ?int $oauthExpiresAt;

    private function __construct(
        string $event,
        string $siteName,
        string $siteUrl,
        string $generatedAt,
        string $connectionId,
        string $connectionLabel,
        string $provider,
        string $status,
        ?string $lastError,
        ?int $oauthExpiresAt
    ) {
        $this->event           = $event;
        $this->siteName        = $siteName;
        $this->siteUrl         = $siteUrl;
        $this->generatedAt     = $generatedAt;
        $this->connectionId    = $connectionId;
        $this->connectionLabel = $connectionLabel;
        $this->provider        = $provider;
        $this->status          = $status;
        $this->lastError       = $lastError;
        $this->oauthExpiresAt  = $oauthExpiresAt;
    }

    public static function unhealthy(Connection $connection, ConnectionHealth $health): self
    {
        return self::fromEvent(self::EVENT_UNHEALTHY, $connection, $health);
    }

    public static function recovered(Connection $connection, ConnectionHealth $health): self
    {
        return self::fromEvent(self::EVENT_RECOVERED, $connection, $health);
    }

    public static function oauthExpiring(Connection $connection, ConnectionHealth $health): self
    {
        return self::fromEvent(self::EVENT_OAUTH_EXPIRING, $connection, $health);
    }

    public static function oauthExpired(Connection $connection, ConnectionHealth $health): self
    {
        return self::fromEvent(self::EVENT_OAUTH_EXPIRED, $connection, $health);
    }

    public function isTest(): bool
    {
        return false;
    }

    public function emailSubject(): string
    {
        return \sprintf('[Bit SMTP] %s on %s', $this->headline(), $this->siteName);
    }

    public function emailBody(): string
    {
        $lines = [
            $this->headline(),
            'Site: ' . $this->siteName,
            'URL: ' . $this->siteUrl,
            'At: ' . $this->generatedAt,
            'Connection: ' . $this->connectionDescriptor(),
            'Status: ' . $this->status,
        ];

        if ($this->lastError !== null && $this->lastError !== '') {
            $lines[] = 'Last error: ' . $this->lastError;
        }
        if ($this->oauthExpiresAt !== null) {
            $lines[] = 'OAuth token expires: ' . gmdate(DATE_ATOM, $this->oauthExpiresAt);
        }

        return implode("\n", $lines);
    }

    public function chatText(): string
    {
        return $this->emailBody();
    }

    public function eventType(): string
    {
        return $this->event;
    }

    public function toArray(): array
    {
        return [
            'event'        => $this->event,
            'generated_at' => $this->generatedAt,
            'site'         => [
                'name' => $this->siteName,
                'url'  => $this->siteUrl,
            ],
            'connection' => [
                'id'       => $this->connectionId,
                'name'     => $this->connectionLabel,
                'provider' => $this->provider,
            ],
            'status'           => $this->status,
            'last_error'       => $this->lastError,
            'oauth_expires_at' => $this->oauthExpiresAt,
        ];
    }

    /**
     * Build the payload from a connection and its health record, pulling only non-sensitive fields.
     */
    private static function fromEvent(string $event, Connection $connection, ConnectionHealth $health): self
    {
        return new self(
            $event,
            (string) get_bloginfo('name'),
            home_url('/'),
            gmdate(DATE_ATOM),
            $connection->getId(),
            $connection->label(),
            $connection->getProvider(),
            $health->getStatus(),
            self::sanitize($health->getLastError()),
            $health->getOauthExpiresAt()
        );
    }

    private function headline(): string
    {
        switch ($this->event) {
            case self::EVENT_RECOVERED:
                return 'Connection recovered';

            case self::EVENT_OAUTH_EXPIRING:
                return 'OAuth token expiring';

            case self::EVENT_OAUTH_EXPIRED:
                return 'OAuth token expired';

            default:
                return 'Connection unhealthy';
        }
    }

    private function connectionDescriptor(): string
    {
        return $this->provider === '' ? $this->connectionLabel : $this->connectionLabel . ' (' . $this->provider . ')';
    }

    /**
     * Reduce a recorded error to a short, single-line string safe to place in an alert.
     */
    private static function sanitize(?string $error): ?string
    {
        if ($error === null || $error === '') {
            return null;
        }

        $clean = trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags($error)));

        return $clean === '' ? null : mb_substr($clean, 0, self::MAX_ERROR_LENGTH);
    }
}
