<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Import;

use BitApps\SMTP\HTTP\Services\MailConfigService;

/**
 * Orchestrates settings import: lists detectable source plugins, previews a mapped connection without
 * persisting, and persists an import through the SAME new-connection save path (MailConfigService), so
 * sanitization and at-rest credential encryption apply exactly as for a manually added connection.
 */
final class ImportService
{
    private PluginImporterRegistry $registry;

    private MailConfigService $mailConfig;

    public function __construct(PluginImporterRegistry $registry, MailConfigService $mailConfig)
    {
        $this->registry   = $registry;
        $this->mailConfig = $mailConfig;
    }

    /**
     * Detected importers as `{key,label}` rows for listing.
     *
     * @return array<int,array{key:string,label:string}>
     */
    public function available(): array
    {
        return array_map(
            static fn (PluginImporterInterface $importer): array => [
                'key'   => $importer->key(),
                'label' => $importer->label(),
            ],
            $this->registry->detected()
        );
    }

    /**
     * The mapped connection for a plugin key WITHOUT persisting (dry-run); null when the key is unknown
     * or the source plugin is not configured.
     *
     * @return null|array<string,mixed>
     */
    public function preview(string $key): ?array
    {
        $importer = $this->registry->get($key);

        return $importer !== null ? $importer->toConnection() : null;
    }

    /**
     * Persist an import as a new connection, returning the minted connection id, or null when there was
     * nothing to import or the store failed.
     */
    public function import(string $key): ?string
    {
        $connection = $this->preview($key);
        if ($connection === null) {
            return null;
        }

        return $this->mailConfig->upsertConnection($connection);
    }
}
