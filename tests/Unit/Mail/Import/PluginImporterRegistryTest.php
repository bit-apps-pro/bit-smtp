<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Mail\Import;

use BitApps\SMTP\Mail\Import\PluginImporterInterface;
use BitApps\SMTP\Mail\Import\PluginImporterRegistry;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use InvalidArgumentException;
use stdClass;

/**
 * @internal
 *
 * @coversNothing
 */
final class PluginImporterRegistryTest extends BaseUnitTestCase
{
    public function testDetectedListsOnlyConfiguredImporters(): void
    {
        $registry = new PluginImporterRegistry([
            $this->fakeImporter('configured', true),
            $this->fakeImporter('absent', false),
        ]);

        $detected = $registry->detected();
        $this->assertCount(1, $detected);
        $this->assertSame('configured', $detected[0]->key());
    }

    public function testGetReturnsImporterByKeyOrNull(): void
    {
        $importer = $this->fakeImporter('configured', true);
        $registry = new PluginImporterRegistry([$importer]);

        $this->assertSame($importer, $registry->get('configured'));
        $this->assertNull($registry->get('missing'));
    }

    public function testRejectsNonImporterInstances(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PluginImporterRegistry([new stdClass()]);
    }

    public function testWithDefaultsRegistersTheBuiltInImporters(): void
    {
        $registry = PluginImporterRegistry::withDefaults();

        $this->assertNotNull($registry->get('wp_mail_smtp'));
        $this->assertNotNull($registry->get('easy_wp_smtp'));
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
                return ucfirst($this->key);
            }

            public function detect(): bool
            {
                return $this->detected;
            }

            public function toConnection(): ?array
            {
                return $this->detected ? ['id' => '', 'provider' => 'other_smtp', 'kind' => 'smtp'] : null;
            }
        };
    }
}
