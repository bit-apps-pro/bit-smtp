<?php

namespace BitApps\SMTP\Mail\Providers;

use BitApps\SMTP\Mail\Contracts\ProviderInterface;
use BitApps\SMTP\Mail\Exceptions\DuplicateProviderException;
use BitApps\SMTP\Mail\Exceptions\ProviderNotFoundException;

class ProviderRegistry
{
    /**
     * @var array<string, ProviderInterface>
     */
    private array $providers = [];

    public function register(ProviderInterface $provider): void
    {
        $key = $provider->key();

        if ($this->has($key)) {
            throw DuplicateProviderException::forKey($key);
        }

        $this->providers[$key] = $provider;
    }

    public function get(string $key): ProviderInterface
    {
        if (!$this->has($key)) {
            throw ProviderNotFoundException::forKey($key);
        }

        return $this->providers[$key];
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    /**
     * @return ProviderInterface[]
     */
    public function all(): array
    {
        return array_values($this->providers);
    }

    /**
     * @return array<int, array{key: string, label: string, kind: string, fields: array}>
     */
    public function metadata(): array
    {
        return array_values(array_map(static function (ProviderInterface $provider): array {
            return [
                'key'    => $provider->key(),
                'label'  => $provider->label(),
                'kind'   => $provider->kind(),
                'fields' => $provider->fields(),
            ];
        }, $this->providers));
    }
}
