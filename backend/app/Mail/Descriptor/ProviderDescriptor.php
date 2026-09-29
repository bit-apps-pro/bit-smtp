<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Descriptor;

use BitApps\SMTP\Mail\Providers\ProviderGuide;
use InvalidArgumentException;

/**
 * Immutable, validated wrapper around a declarative provider config array (spec §4.3).
 *
 * @phpstan-import-type Guide from ProviderGuide
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

    /**
     * @var array{equals?: array<string,mixed>, requiredNonEmptyStringPaths?: list<string>}
     */
    private array $semanticSuccess;

    private array $errorPaths;

    private array $errorDetectPaths;

    /**
     * @var callable|null
     */
    private $payloadBuilder;

    private string $messageIdPath;

    private array $tracking;

    /**
     * @var Guide|array{}
     */
    private array $guide;

    /**
     * @param array{equals?: array<string,mixed>, requiredNonEmptyStringPaths?: list<string>} $semanticSuccess
     * @param Guide|array{}                                                                   $guide
     */
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
        array $semanticSuccess,
        array $errorPaths,
        array $errorDetectPaths,
        ?callable $payloadBuilder,
        string $messageIdPath,
        array $tracking,
        array $guide
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
        $this->semanticSuccess  = $semanticSuccess;
        $this->errorPaths       = $errorPaths;
        $this->errorDetectPaths = $errorDetectPaths;
        $this->payloadBuilder   = $payloadBuilder;
        $this->messageIdPath    = $messageIdPath;
        $this->tracking         = $tracking;
        $this->guide            = $guide;
    }

    public static function fromArray(array $config): self
    {
        foreach (['key', 'label', 'kind'] as $required) {
            if (empty($config[$required])) {
                throw new InvalidArgumentException(esc_html("ProviderDescriptor missing required key: {$required}"));
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
            $config['semanticSuccess']  ?? [],
            $config['errorPaths']       ?? [],
            $config['errorDetectPaths'] ?? [],
            $config['payloadBuilder']   ?? null,
            (string) ($config['messageIdPath'] ?? ''),
            $config['tracking']         ?? [],
            $config['guide']            ?? []
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

    /**
     * Optional response-body requirements supplementing HTTP success; dot-paths for values and non-empty strings.
     *
     * @return array{equals?: array<string,mixed>, requiredNonEmptyStringPaths?: list<string>}
     */
    public function semanticSuccess(): array
    {
        return $this->semanticSuccess;
    }

    public function errorPaths(): array
    {
        return $this->errorPaths;
    }

    /**
     * Opt-in dot-paths flagging provider error in 2xx bodies; distinct from errorPaths.
     */
    public function errorDetectPaths(): array
    {
        return $this->errorDetectPaths;
    }

    public function payloadBuilder(): ?callable
    {
        return $this->payloadBuilder;
    }

    /**
     * Response dot-path to provider message-id (e.g. 'MessageID', 'results.id'); '' if unexposed.
     */
    public function messageIdPath(): string
    {
        return $this->messageIdPath;
    }

    /**
     * Correlation token carrier: 'metadata'|'header' channel with key, or [] if unsupported.
     */
    public function tracking(): array
    {
        return $this->tracking;
    }

    /**
     * Setup guide shown in the provider picker, or [] when the descriptor declares none.
     *
     * @return Guide|array{}
     */
    public function guide(): array
    {
        return $this->guide;
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
