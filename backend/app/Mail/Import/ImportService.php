<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Import;

use BitApps\SMTP\HTTP\Services\MailConfigService;

/**
 * Orchestrate settings import via MailConfigService for consistent sanitization and encryption.
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
     * Get mapped connection for a plugin key without persisting (dry-run).
     *
     * @return null|array<string,mixed>
     */
    public function preview(string $key): ?array
    {
        $importer = $this->registry->get($key);

        return $importer !== null ? $importer->toConnection() : null;
    }

    /**
     * Persist import as new connection; return connection id or null on failure.
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
