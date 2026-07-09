<?php

namespace BitApps\SMTP\Mail\Message;

class SendResult
{
    private bool $ok;

    private ?string $code;

    private ?string $error;

    private array $debug;

    private function __construct(bool $ok, ?string $code, ?string $error, array $debug)
    {
        $this->ok    = $ok;
        $this->code  = $code;
        $this->error = $error;
        $this->debug = $debug;
    }

    public static function success(array $debug = []): self
    {
        return new self(true, null, null, $debug);
    }

    public static function failure(string $error, ?string $code = null, array $debug = []): self
    {
        return new self(false, $code, $error, $debug);
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
