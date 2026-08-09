<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Routing;

final class RoutingDecision
{
    private string $sourcePlugin;

    private ?string $connectionId;

    private string $type;

    private ?int $ruleIndex;

    public function __construct(string $sourcePlugin, ?string $connectionId, string $type, ?int $ruleIndex)
    {
        $this->sourcePlugin = $sourcePlugin;
        $this->connectionId = $connectionId;
        $this->type         = $type;
        $this->ruleIndex    = $ruleIndex;
    }

    public function sourcePlugin(): string
    {
        return $this->sourcePlugin;
    }

    public function connectionId(): ?string
    {
        return $this->connectionId;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function ruleIndex(): ?int
    {
        return $this->ruleIndex;
    }

    public function withType(string $type): self
    {
        return new self($this->sourcePlugin, $this->connectionId, $type, $this->ruleIndex);
    }
}
