<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Descriptor;

use BitApps\SMTP\Mail\Descriptor\ProviderDescriptor;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use InvalidArgumentException;

/**
 * @internal
 *
 * @coversNothing
 */
class ProviderDescriptorTest extends BaseUnitTestCase
{
    public function testFromArrayRoundTripsFullConfig(): void
    {
        $config     = $this->fullConfig();
        $descriptor = ProviderDescriptor::fromArray($config);

        $this->assertSame($config['key'], $descriptor->key());
        $this->assertSame($config['label'], $descriptor->label());
        $this->assertSame($config['kind'], $descriptor->kind());
        $this->assertSame($config['fields'], $descriptor->fields());
        $this->assertSame($config['auth'], $descriptor->auth());
        $this->assertSame($config['endpoint'], $descriptor->endpoint());
        $this->assertSame($config['encoder'], $descriptor->encoder());
        $this->assertSame($config['payload'], $descriptor->payload());
        $this->assertSame($config['success'], $descriptor->success());
        $this->assertSame($config['errorPaths'], $descriptor->errorPaths());
        $this->assertSame($config['errorDetectPaths'], $descriptor->errorDetectPaths());
        $this->assertSame($config['payloadBuilder'], $descriptor->payloadBuilder());
        $this->assertTrue(\is_callable($descriptor->payloadBuilder()));
        $this->assertSame(['built' => ['to' => 'a@x.com']], ($descriptor->payloadBuilder())(['to' => 'a@x.com']));
    }

    public function testOptionalKeysOmittedReturnDocumentedDefaults(): void
    {
        $descriptor = ProviderDescriptor::fromArray($this->minimalConfig());

        $this->assertSame([], $descriptor->fields());
        $this->assertSame([], $descriptor->endpoint());
        $this->assertSame('', $descriptor->encoder());
        $this->assertSame([], $descriptor->payload());
        $this->assertSame([], $descriptor->success());
        $this->assertSame([], $descriptor->errorPaths());
        $this->assertSame([], $descriptor->errorDetectPaths());
        $this->assertNull($descriptor->payloadBuilder());
    }

    public function testMissingKeyThrowsInvalidArgumentException(): void
    {
        $config = $this->minimalConfig();
        unset($config['key']);

        $this->expectException(InvalidArgumentException::class);

        ProviderDescriptor::fromArray($config);
    }

    public function testEmptyKeyThrowsInvalidArgumentException(): void
    {
        $config         = $this->minimalConfig();
        $config['key']  = '';

        $this->expectException(InvalidArgumentException::class);

        ProviderDescriptor::fromArray($config);
    }

    public function testMissingLabelThrowsInvalidArgumentException(): void
    {
        $config = $this->minimalConfig();
        unset($config['label']);

        $this->expectException(InvalidArgumentException::class);

        ProviderDescriptor::fromArray($config);
    }

    public function testMissingKindThrowsInvalidArgumentException(): void
    {
        $config = $this->minimalConfig();
        unset($config['kind']);

        $this->expectException(InvalidArgumentException::class);

        ProviderDescriptor::fromArray($config);
    }

    public function testMissingAuthThrowsInvalidArgumentException(): void
    {
        $config = $this->minimalConfig();
        unset($config['auth']);

        $this->expectException(InvalidArgumentException::class);

        ProviderDescriptor::fromArray($config);
    }

    public function testAuthNotArrayThrowsInvalidArgumentException(): void
    {
        $config         = $this->minimalConfig();
        $config['auth'] = 'bearer';

        $this->expectException(InvalidArgumentException::class);

        ProviderDescriptor::fromArray($config);
    }

    public function testAuthWithoutTypeThrowsInvalidArgumentException(): void
    {
        $config         = $this->minimalConfig();
        $config['auth'] = ['params' => ['token' => 'x']];

        $this->expectException(InvalidArgumentException::class);

        ProviderDescriptor::fromArray($config);
    }

    public function testAuthWithEmptyTypeThrowsInvalidArgumentException(): void
    {
        $config         = $this->minimalConfig();
        $config['auth'] = ['type' => '', 'params' => []];

        $this->expectException(InvalidArgumentException::class);

        ProviderDescriptor::fromArray($config);
    }

    public function testAuthWithNonStringScalarTypeThrowsInvalidArgumentException(): void
    {
        $config         = $this->minimalConfig();
        $config['auth'] = ['type' => 42];

        $this->expectException(InvalidArgumentException::class);

        ProviderDescriptor::fromArray($config);
    }

    public function testAuthWithArrayTypeThrowsInvalidArgumentException(): void
    {
        $config         = $this->minimalConfig();
        $config['auth'] = ['type' => ['bearer']];

        $this->expectException(InvalidArgumentException::class);

        ProviderDescriptor::fromArray($config);
    }

    private function fullConfig(): array
    {
        return [
            'key'    => 'sendgrid',
            'label'  => 'SendGrid',
            'kind'   => 'api',
            'fields' => [
                [
                    'key'      => 'api_key',
                    'label'    => 'API Key',
                    'type'     => 'text',
                    'required' => true,
                    'secret'   => true,
                ],
            ],
            'auth' => [
                'type'   => 'bearer',
                'params' => ['token' => '{api_key}'],
            ],
            'endpoint' => [
                'host'         => 'api.cloudflare.com',
                'path'         => '/client/v4/accounts/{account_id}/email/sending/send',
                'pathSettings' => ['account_id' => '/^[a-f0-9]{32}$/iD'],
            ],
            'encoder' => 'json',
            'payload' => [
                'map'             => ['subject' => 'subject'],
                'addressShape'    => 'object',
                'attachmentShape' => 'base64',
                'envelope'        => ['from' => 'from'],
            ],
            'success'          => [200, 202],
            'errorPaths'       => ['errors.0.message', 'message'],
            'errorDetectPaths' => ['errors.0.message'],
            'payloadBuilder'   => static function (array $message): array {
                return ['built' => $message];
            },
        ];
    }

    private function minimalConfig(): array
    {
        return [
            'key'   => 'sendgrid',
            'label' => 'SendGrid',
            'kind'  => 'api',
            'auth'  => ['type' => 'bearer'],
        ];
    }
}
