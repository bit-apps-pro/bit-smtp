<?php

namespace BitApps\SMTP\Mail\Dispatch;

use BitApps\SMTP\Mail\Routing\RoutingDecision;

/**
 * Mutable per-send state shared between wp_mail hooks and caller; hook callbacks write outcome fields back.
 */
class SendContext
{
    private bool $debug = false;

    /**
     * @var array<int,string>
     */
    private array $debugOutput = [];

    private bool $isFailed = false;

    private bool $isRetrying = false;

    private int $retryLogId = 0;

    private ?int $resendParentId = null;

    private bool $isBatch = false;

    private ?RoutingDecision $routingDecision = null;

    /**
     * Raw wp_mail headers captured pre-dispatch; needed to recover From/Cc/Bcc core strips before wp_mail_succeeded/failed.
     *
     * @var string|array<int,string>
     */
    private $nativeMailHeaders = '';

    /**
     * Clear per-send output accumulators and routing metadata; preserve caller-set inputs for batch/resend loops.
     */
    public function resetForSend(): void
    {
        $this->debugOutput     = [];
        $this->isFailed        = false;
        $this->routingDecision = null;
    }

    public function setDebug(bool $debug): self
    {
        $this->debug = $debug;

        return $this;
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    public function appendDebug(string $line): void
    {
        $this->debugOutput[] = $line;
    }

    /**
     * @return array<int,string>
     */
    public function getDebugOutput(): array
    {
        return $this->debugOutput;
    }

    public function setFailed(bool $isFailed): self
    {
        $this->isFailed = $isFailed;

        return $this;
    }

    public function isFailed(): bool
    {
        return $this->isFailed;
    }

    public function setRetrying(bool $isRetrying): self
    {
        $this->isRetrying = $isRetrying;

        return $this;
    }

    public function isRetrying(): bool
    {
        return $this->isRetrying;
    }

    public function setRetryLogId(int $retryLogId): self
    {
        $this->retryLogId = $retryLogId;

        return $this;
    }

    public function getRetryLogId(): int
    {
        return $this->retryLogId;
    }

    /**
     * Parent log id for a resend; inserted as child row id (null on non-resend sends).
     */
    public function setResendParentId(?int $resendParentId): self
    {
        $this->resendParentId = $resendParentId;

        return $this;
    }

    public function getResendParentId(): ?int
    {
        return $this->resendParentId;
    }

    public function setBatch(bool $isBatch): self
    {
        $this->isBatch = $isBatch;

        return $this;
    }

    public function isBatch(): bool
    {
        return $this->isBatch;
    }

    public function setRoutingDecision(RoutingDecision $routingDecision): self
    {
        $this->routingDecision = $routingDecision;

        return $this;
    }

    public function getRoutingDecision(): ?RoutingDecision
    {
        return $this->routingDecision;
    }

    /**
     * Stash raw headers so native-path listeners recover From/Cc/Bcc core strips before wp_mail_succeeded/failed.
     *
     * @param string|array<int,string> $headers
     */
    public function setNativeMailHeaders($headers): self
    {
        $this->nativeMailHeaders = $headers;

        return $this;
    }

    /**
     * @return string|array<int,string>
     */
    public function getNativeMailHeaders()
    {
        return $this->nativeMailHeaders;
    }
}
