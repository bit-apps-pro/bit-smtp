<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Descriptor;

use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Contracts\ProviderInterface;
use BitApps\SMTP\Mail\Contracts\TransportInterface;
use BitApps\SMTP\Mail\Contracts\ValidatorInterface;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Support\EncoderInterface;
use BitApps\SMTP\Mail\Support\FormEncoder;
use BitApps\SMTP\Mail\Support\FormMultipartEncoder;
use BitApps\SMTP\Mail\Support\JsonEncoder;
use BitApps\SMTP\Mail\Support\MimeRawEncoder;
use BitApps\SMTP\Mail\Validation\RequiredFieldsValidator;
use InvalidArgumentException;

/**
 * Adapts a ProviderDescriptor to ProviderInterface: metadata is delegated to the descriptor and
 * transport() assembles a DescriptorApiTransport wired with the resolved auth strategy + encoder.
 * The ctor signature is shared by every descriptor-backed provider.
 */
class DescriptorProvider implements ProviderInterface
{
    private ProviderDescriptor $descriptor;

    private ApiClient $client;

    private AuthorizationResolver $resolver;

    public function __construct(ProviderDescriptor $descriptor, ApiClient $client, AuthorizationResolver $resolver)
    {
        $this->descriptor = $descriptor;
        $this->client     = $client;
        $this->resolver   = $resolver;
    }

    public function key(): string
    {
        return $this->descriptor->key();
    }

    public function label(): string
    {
        return $this->descriptor->label();
    }

    public function kind(): string
    {
        return $this->descriptor->kind();
    }

    public function fields(): array
    {
        return $this->descriptor->fields();
    }

    public function defaults(): array
    {
        $defaults = [];

        foreach ($this->descriptor->fields() as $field) {
            if (isset($field['key'])) {
                $defaults[$field['key']] = $field['default'] ?? '';
            }
        }

        return $defaults;
    }

    public function validator(): ValidatorInterface
    {
        return new RequiredFieldsValidator($this->requiredFields());
    }

    public function transport(): TransportInterface
    {
        return new DescriptorApiTransport(
            $this->client,
            $this->descriptor,
            $this->resolver->resolveFromConfig($this->descriptor->auth()),
            $this->encoderFor($this->descriptor->encoder())
        );
    }

    public function authConfig(): array
    {
        return $this->descriptor->auth();
    }

    /**
     * How this provider carries a delivery-webhook correlation token on send:
     * ['channel' => 'metadata'|'header', 'key' => string], or [] when unsupported.
     */
    public function tracking(): array
    {
        return $this->descriptor->tracking();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function requiredFields(): array
    {
        return array_values(array_filter($this->descriptor->fields(), static function (array $field): bool {
            return !empty($field['required']);
        }));
    }

    private function encoderFor(string $encoder): EncoderInterface
    {
        switch ($encoder) {
            case 'json':
                return new JsonEncoder();
            case 'form':
                return new FormEncoder();
            case 'mime_raw':
                return new MimeRawEncoder();
            case 'multipart':
                return new FormMultipartEncoder();
            default:
                throw new InvalidArgumentException("Unsupported encoder: {$encoder}");
        }
    }
}
