<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Import;

use InvalidArgumentException;

/**
 * Registry of SMTP plugins Bit SMTP can import from; keyed by importer slug.
 */
final class PluginImporterRegistry
{
    /**
     * @var array<string,PluginImporterInterface>
     */
    private array $importers = [];

    /**
     * @param PluginImporterInterface[] $importers
     */
    public function __construct(array $importers)
    {
        foreach ($importers as $importer) {
            if (!$importer instanceof PluginImporterInterface) {
                throw new InvalidArgumentException('PluginImporterRegistry expects PluginImporterInterface instances.');
            }

            $this->importers[$importer->key()] = $importer;
        }
    }

    /**
     * The built-in importers shipped with the plugin.
     */
    public static function withDefaults(): self
    {
        return new self([
            new WpMailSmtpImporter(),
            new EasyWpSmtpImporter(),
        ]);
    }

    /**
     * Only importers whose source plugin holds an importable configuration on this site.
     *
     * @return PluginImporterInterface[]
     */
    public function detected(): array
    {
        return array_values(array_filter(
            $this->importers,
            static fn (PluginImporterInterface $importer): bool => $importer->detect()
        ));
    }

    /**
     * The importer for a plugin key, or null when unknown.
     */
    public function get(string $key): ?PluginImporterInterface
    {
        return $this->importers[$key] ?? null;
    }
}
