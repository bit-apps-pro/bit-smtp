<?php

namespace BitApps\SMTP\Mail\Message;

class SendResult
{
    private bool $accepted;

    private bool $ok;

    private ?string $code;

    private ?string $error;

    private array $debug;

    private ?string $messageId;

    private function __construct(bool $accepted, bool $ok, ?string $code, ?string $error, array $debug, ?string $messageId = null)
    {
        $this->accepted  = $accepted;
        $this->ok        = $ok;
        $this->code      = $code;
        $this->error     = $error;
        $this->debug     = $debug;
        $this->messageId = $messageId;
    }

    public static function success(array $debug = []): self
    {
        return new self(true, true, null, null, $debug);
    }

    /**
     * Result when provider never handed off message; dispatch fallback tries next connection.
     */
    public static function failure(string $error, ?string $code = null, array $debug = []): self
    {
        return new self(false, false, $code, $error, $debug);
    }

    /**
     * Result when provider accepted message but reported error; not eligible for fallback.
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

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    /**
     * Return immutable copy with provider's message-id for delivery-webhook correlation.
     */
    public function withMessageId(?string $messageId): self
    {
        return new self($this->accepted, $this->ok, $this->code, $this->error, $this->debug, $messageId);
    }
}
