<?php

namespace BitApps\SMTP\Mail\Exceptions;

use BitApps\SMTP\Mail\Message\SendResult;
use RuntimeException;

/**
 * A failed OAuth token refresh, carrying the SendResult code that tells a revoked sign-in apart from a passing outage.
 */
class OAuthException extends RuntimeException
{
    private const RETRYABLE_STATUSES = [408, 429];

    private const RETRYABLE_ERRORS = ['server_error', 'temporarily_unavailable'];

    private ?string $sendResultCode;

    /**
     * Keep the plain-text message, escaped only where it is displayed, with the send-result code it maps to.
     */
    private function __construct(string $message, ?string $sendResultCode)
    {
        parent::__construct($message);
        $this->sendResultCode = $sendResultCode;
    }

    /**
     * No refresh token is stored, so only signing in again can fix it.
     */
    public static function missingRefreshToken(): self
    {
        return new self(
            __('OAuth refresh failed: no refresh token is stored for this connection. Reconnect it to sign in again.', 'bit-smtp'),
            SendResult::CODE_AUTH_REJECTED
        );
    }

    /**
     * The token endpoint refused a refresh; $oauthError is the RFC 6749 error code, already vetted as safe, or '' when absent.
     */
    public static function refreshFailed(int $status, string $oauthError): self
    {
        $reason = $oauthError !== '' ? $oauthError : 'HTTP ' . $status;

        if (self::isRejected($status, $oauthError)) {
            // translators: %s: OAuth error code such as invalid_grant, or an HTTP status
            return new self(\sprintf(__('OAuth token refresh failed: %s. Reconnect this connection to sign in again.', 'bit-smtp'), $reason), SendResult::CODE_AUTH_REJECTED);
        }

        // translators: %s: OAuth error code, or an HTTP status
        return new self(\sprintf(__('OAuth token refresh failed: %s', 'bit-smtp'), $reason), self::retryableCode($status));
    }

    /**
     * SendResult code for this failure: auth-rejected, the HTTP status for a throttled or failing server, or null to fall back on the message.
     */
    public function sendResultCode(): ?string
    {
        return $this->sendResultCode;
    }

    /**
     * A 4xx answer other than a timeout or throttle means the stored sign-in or client credentials were refused, unless the provider says it was only busy.
     */
    private static function isRejected(int $status, string $oauthError): bool
    {
        return $status >= 400 && $status < 500
                              && !\in_array($status, self::RETRYABLE_STATUSES, true)
                              && !\in_array($oauthError, self::RETRYABLE_ERRORS, true);
    }

    /**
     * Keep the status for a throttled request or a server error so the classifier can read it; null for anything else.
     */
    private static function retryableCode(int $status): ?string
    {
        return $status >= 500 || \in_array($status, self::RETRYABLE_STATUSES, true) ? (string) $status : null;
    }
}
