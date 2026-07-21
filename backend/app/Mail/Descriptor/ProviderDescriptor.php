<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Descriptor;

use InvalidArgumentException;

/**
 * Immutable, validated wrapper around a declarative provider config array (spec §4.3).
 */
final class ProviderDescriptor
{
    private string $key;

    private string $label;

    private string $kind;

    private array $fields;

    private array $auth;

    private array $endpoint;

    private string $encoder;

    private array $payload;

    private array $success;

    private array $errorPaths;

    private array $errorDetectPaths;

    /**
     * @var callable|null
     */
    private $payloadBuilder;

    private function __construct(
        string $key,
        string $label,
        string $kind,
        array $fields,
        array $auth,
        array $endpoint,
        string $encoder,
        array $payload,
        array $success,
        array $errorPaths,
        array $errorDetectPaths,
        ?callable $payloadBuilder
    ) {
        $this->key              = $key;
        $this->label            = $label;
        $this->kind             = $kind;
        $this->fields           = $fields;
        $this->auth             = $auth;
        $this->endpoint         = $endpoint;
        $this->encoder          = $encoder;
        $this->payload          = $payload;
        $this->success          = $success;
        $this->errorPaths       = $errorPaths;
        $this->errorDetectPaths = $errorDetectPaths;
        $this->payloadBuilder   = $payloadBuilder;
    }

    public static function fromArray(array $config): self
    {
        foreach (['key', 'label', 'kind'] as $required) {
            if (empty($config[$required])) {
                throw new InvalidArgumentException("ProviderDescriptor missing required key: {$required}");
            }
        }

        $auth = self::validatedAuth($config);

        return new self(
            (string) $config['key'],
            (string) $config['label'],
            (string) $config['kind'],
            $config['fields'] ?? [],
            $auth,
            $config['endpoint'] ?? [],
            (string) ($config['encoder'] ?? ''),
            $config['payload']          ?? [],
            $config['success']          ?? [],
            $config['errorPaths']       ?? [],
            $config['errorDetectPaths'] ?? [],
            $config['payloadBuilder']   ?? null
        );
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * HTTP auth descriptor for AuthorizationResolver: ['type' => string, 'params' => array].
     */
    public function auth(): array
    {
        return $this->auth;
    }

    public function endpoint(): array
    {
        return $this->endpoint;
    }

    public function encoder(): string
    {
        return $this->encoder;
    }

    public function payload(): array
    {
        return $this->payload;
    }

    public function success(): array
    {
        return $this->success;
    }

    public function errorPaths(): array
    {
        return $this->errorPaths;
    }

    /**
     * Opt-in dot-paths that flag a provider error in a 2xx response body (e.g. Mailjet's per-message
     * failures under HTTP 200). Empty by default, so success stays status-only for every other
     * provider — distinct from errorPaths, which only extracts a message on the failure path.
     */
    public function errorDetectPaths(): array
    {
        return $this->errorDetectPaths;
    }

    public function payloadBuilder(): ?callable
    {
        return $this->payloadBuilder;
    }

    private static function validatedAuth(array $config): array
    {
        if (!\array_key_exists('auth', $config)) {
            throw new InvalidArgumentException('ProviderDescriptor missing required key: auth');
        }

        if (!\is_array($config['auth'])) {
            throw new InvalidArgumentException("ProviderDescriptor: 'auth' must be an array");
        }

        $auth = $config['auth'];

        if (empty($auth['type']) || !\is_string($auth['type'])) {
            throw new InvalidArgumentException('ProviderDescriptor missing required key: auth.type');
        }

        return $auth;
    }
}
