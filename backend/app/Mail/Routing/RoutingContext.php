<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Routing;

final class RoutingContext
{
    /**
     * @var string[]
     */
    private array $recipients;

    private string $from;

    private string $subject;

    private string $sourcePlugin;

    private function __construct(array $recipients, string $from, string $subject, string $sourcePlugin)
    {
        $this->recipients   = $recipients;
        $this->from         = $from;
        $this->subject      = $subject;
        $this->sourcePlugin = $sourcePlugin;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            array_values(array_map('strval', (array) ($data['recipients'] ?? []))),
            (string) ($data['from'] ?? ''),
            (string) ($data['subject'] ?? ''),
            (string) ($data['sourcePlugin'] ?? '')
        );
    }

    /**
     * @return string[]
     */
    public function getRecipients(): array
    {
        return $this->recipients;
    }

    public function getFrom(): string
    {
        return $this->from;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getSourcePlugin(): string
    {
        return $this->sourcePlugin;
    }
}
