<?php

namespace BitApps\SMTP\Mail\Exceptions;

use BitApps\SMTP\Mail\OAuth\OAuthFailureReason;
use RuntimeException;

/**
 * OAuth callback failure carrying a safe OAuthFailureReason code for the admin UI.
 */
class OAuthCallbackException extends RuntimeException
{
    private string $reason;

    public function __construct(string $reason, string $message)
    {
        parent::__construct($message);
        $this->reason = $reason;
    }

    /**
     * Failure with the given reason code.
     */
    public static function because(string $reason): self
    {
        return new self($reason, "OAuth callback failed: {$reason}.");
    }

    /**
     * The OAuthFailureReason code.
     */
    public function reason(): string
    {
        return $this->reason;
    }
}
