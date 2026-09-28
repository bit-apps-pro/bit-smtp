<?php

namespace BitApps\SMTP\Mail\Health;

/**
 * Active connection probe outcome: liveness + failure reason + classified FailureCategory.
 */
final class ProbeResult
{
    private bool $ok;

    private ?string $error;

    private ?string $failureClass;

    private function __construct(bool $ok, ?string $error, ?string $failureClass)
    {
        $this->ok           = $ok;
        $this->error        = $error;
        $this->failureClass = $failureClass;
    }

    /**
     * A connection that connected and authenticated cleanly.
     */
    public static function ok(): self
    {
        return new self(true, null, null);
    }

    /**
     * A connection whose probe failed, carrying a sanitized reason and its classified category.
     */
    public static function failure(string $error, ?string $failureClass = null): self
    {
        return new self(false, $error, $failureClass);
    }

    public function isOk(): bool
    {
        return $this->ok;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getFailureClass(): ?string
    {
        return $this->failureClass;
    }
}
