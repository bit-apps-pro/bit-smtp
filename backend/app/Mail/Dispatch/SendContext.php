<?php

namespace BitApps\SMTP\Mail\Dispatch;

use BitApps\SMTP\Mail\Routing\RoutingDecision;

/**
 * Mutable per-send state shared between the wp_mail hooks and the calling controller.
 *
 * Deliberately NOT a value object: the hook callbacks write outcome fields (isFailed,
 * debugOutput) that the caller reads back off the same instance after wp_mail() returns.
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

    private bool $isBatch = false;

    private ?RoutingDecision $routingDecision = null;

    /**
     * Clear per-send output accumulators and routing metadata at the start of each send.
     *
     * Caller-set inputs (debug flag, isRetrying, retryLogId, isBatch) are intentionally
     * preserved so batch/resend loops keep their configuration across sends.
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
}
