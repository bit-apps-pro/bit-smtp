<?php

namespace BitApps\SMTP\Mail\Message;

class SendResult
{
    private bool $accepted;

    private bool $ok;

    private ?string $code;

    private ?string $error;

    private array $debug;

    private function __construct(bool $accepted, bool $ok, ?string $code, ?string $error, array $debug)
    {
        $this->accepted = $accepted;
        $this->ok       = $ok;
        $this->code     = $code;
        $this->error    = $error;
        $this->debug    = $debug;
    }

    public static function success(array $debug = []): self
    {
        return new self(true, true, null, null, $debug);
    }

    /**
     * The provider never handed off the message (network error, 4xx/5xx, etc.): the dispatch
     * fallback is expected to try the next connection.
     */
    public static function failure(string $error, ?string $code = null, array $debug = []): self
    {
        return new self(false, false, $code, $error, $debug);
    }

    /**
     * The provider accepted/handed off the message (e.g. HTTP 2xx) but reported a per-message
     * error in the body: NOT eligible for fallback (a re-send would duplicate-deliver to the
     * recipients already accepted), yet reported as a failed send.
     */
    public static function acceptedWithError(string $error, ?string $code = null, array $debug = []): self
    {
        return new self(true, false, $code, $error, $debug);
    }

    public function isAccepted(): bool
    {
        return $this->accepted;
    }

    public function isOk(): bool
    {
        return $this->ok;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getDebug(): array
    {
        return $this->debug;
    }
}
