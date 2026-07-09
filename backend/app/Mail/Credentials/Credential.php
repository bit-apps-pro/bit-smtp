<?php

namespace BitApps\SMTP\Mail\Credentials;

class Credential
{
    private string $source;

    private ?string $value;

    private function __construct(string $source, ?string $value)
    {
        $this->source = $source;
        $this->value  = $value;
    }

    public static function fromArray(array $data): self
    {
        if (!array_key_exists('source', $data)) {
            throw new \InvalidArgumentException("Missing required key: source");
        }

        return new self($data['source'], $data['value'] ?? null);
    }

    public function getSource(): string { return $this->source; }

    public function getValue(): ?string { return $this->value; }

    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'value'  => $this->value,
        ];
    }
}
