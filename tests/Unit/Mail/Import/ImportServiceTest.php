<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Mail\Import;

use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Import\ImportService;
use BitApps\SMTP\Mail\Import\PluginImporterInterface;
use BitApps\SMTP\Mail\Import\PluginImporterRegistry;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Mockery;

/**
 * @internal
 *
 * @coversNothing
 */
final class ImportServiceTest extends BaseUnitTestCase
{
    public function testAvailableListsOnlyDetectedImporters(): void
    {
        $service = new ImportService(
            new PluginImporterRegistry([
                $this->fakeImporter('a', true),
                $this->fakeImporter('b', false),
            ]),
            Mockery::mock(MailConfigService::class)
        );

        $this->assertSame([['key' => 'a', 'label' => 'A']], $service->available());
    }

    public function testPreviewReturnsMappingWithoutPersisting(): void
    {
        $mailConfig = Mockery::mock(MailConfigService::class);
        $mailConfig->shouldNotReceive('upsertConnection');

        $service = new ImportService(
            new PluginImporterRegistry([$this->fakeImporter('a', true)]),
            $mailConfig
        );

        $this->assertSame('other_smtp', $service->preview('a')['provider']);
        $this->assertNull($service->preview('unknown'));
    }

    public function testImportPersistsThroughTheSharedSavePathAndReturnsId(): void
    {
        $importer   = $this->fakeImporter('a', true);
        $mailConfig = Mockery::mock(MailConfigService::class);
        $mailConfig->shouldReceive('upsertConnection')
            ->once()
            ->with($importer->toConnection())
            ->andReturn('conn_new');

        $service = new ImportService(new PluginImporterRegistry([$importer]), $mailConfig);

        $this->assertSame('conn_new', $service->import('a'));
    }

    public function testImportReturnsNullWhenNothingToImport(): void
    {
        $mailConfig = Mockery::mock(MailConfigService::class);
        $mailConfig->shouldNotReceive('upsertConnection');

        $service = new ImportService(
            new PluginImporterRegistry([$this->fakeImporter('a', false)]),
            $mailConfig
        );

        $this->assertNull($service->import('a'));
        $this->assertNull($service->import('unknown'));
    }

    private function fakeImporter(string $key, bool $detected): PluginImporterInterface
    {
        return new class($key, $detected) implements PluginImporterInterface {
            private string $key;

            private bool $detected;

            public function __construct(string $key, bool $detected)
            {
                $this->key      = $key;
                $this->detected = $detected;
            }

            public function key(): string
            {
                return $this->key;
            }

            public function label(): string
            {
                return strtoupper($this->key);
            }

            public function detect(): bool
            {
                return $this->detected;
            }

            public function toConnection(): ?array
            {
                return $this->detected
                    ? ['id' => '', 'provider' => 'other_smtp', 'kind' => 'smtp', 'name' => $this->key]
                    : null;
            }
        };
    }
}
