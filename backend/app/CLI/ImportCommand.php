<?php

namespace BitApps\SMTP\CLI;

use BitApps\SMTP\Mail\Import\ImportService;

\defined('ABSPATH') || exit();

/**
 * `wp bit-smtp import [<plugin-key>] [--dry-run]`: with no key, list the SMTP plugins detected on this
 * site; with a key, map that plugin's settings into a new Bit SMTP connection (or, with --dry-run,
 * print the mapping without persisting). Persistence reuses ImportService's shared save path so
 * imported passwords are encrypted at rest exactly like a manually added connection.
 */
final class ImportCommand
{
    private ImportService $import;

    public function __construct(ImportService $import)
    {
        $this->import = $import;
    }

    /**
     * List detected plugins when no key is given; otherwise dry-run print or import the given key.
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assocArgs
     */
    public function run(array $args, array $assocArgs, CliReporter $reporter): void
    {
        $key = trim($args[0] ?? '');
        if ($key === '') {
            $this->listDetected($reporter);

            return;
        }

        $connection = $this->import->preview($key);
        if ($connection === null) {
            $reporter->error(\sprintf(
                "No importable configuration found for '%s'. Run `wp bit-smtp import` to list detectable plugins.",
                $key
            ));

            return;
        }

        if (isset($assocArgs['dry-run'])) {
            // Never emit secret values: mask credentials before printing the mapping.
            $reporter->line((string) wp_json_encode($this->redactSecrets($connection), JSON_PRETTY_PRINT));

            return;
        }

        $connId = $this->import->import($key);
        if ($connId === null) {
            $reporter->error(\sprintf("Failed to import '%s' into a new connection.", $key));

            return;
        }

        $reporter->success(\sprintf("Imported '%s' into connection %s.", $key, $connId));
    }

    /**
     * Render the detected importers as a key/label table, or a notice when none are found.
     */
    private function listDetected(CliReporter $reporter): void
    {
        $available = $this->import->available();
        if ($available === []) {
            $reporter->line('No supported SMTP plugins with an importable configuration were detected.');

            return;
        }

        $reporter->renderItems('table', $available, ['key', 'label']);
    }

    /**
     * Replace every credential value with the mask sentinel (non-empty) or '' so no secret is printed.
     *
     * @param array<string,mixed> $connection
     *
     * @return array<string,mixed>
     */
    private function redactSecrets(array $connection): array
    {
        if (!isset($connection['credentials']) || !\is_array($connection['credentials'])) {
            return $connection;
        }

        foreach ($connection['credentials'] as $name => $credential) {
            if (\is_array($credential) && \array_key_exists('value', $credential)) {
                $connection['credentials'][$name]['value'] = $credential['value'] !== '' ? '********' : '';
            }
        }

        return $connection;
    }
}
