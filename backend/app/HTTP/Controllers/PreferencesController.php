<?php

namespace BitApps\SMTP\HTTP\Controllers;

use BitApps\SMTP\Deps\BitApps\WPKit\Helpers\Arr;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\Deps\BitApps\WPKit\Settings\SettingField;
use BitApps\SMTP\HTTP\Requests\SavePreferencesRequest;
use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Plugin;
use BitApps\SMTP\Settings\PluginSettings;

/**
 * REST endpoints for reading, saving, exporting, and importing the plugin's global preferences.
 */
class PreferencesController
{
    /**
     * Preference keys backed by a canonical side-effecting writer on LogService, rather than the
     * blob-only fill(); routed separately in persist() so their continuity/legacy-option side
     * effects fire regardless of which endpoint (save/import) persists them.
     */
    private const LOG_SERVICE_MANAGED_KEYS = ['logging_enabled', 'log_retention_days'];

    /**
     * Lazily resolved so index()/export() and any save() payload without a managed key never force
     * LogService's construction (it side-effects via initializeLoggingContinuity()).
     */
    private ?LogService $logger;

    public function __construct(?LogService $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Return the current preferences plus schema-derived field metadata for the settings UI.
     */
    public function index(): Response
    {
        return Response::success([
            'preferences' => PluginSettings::make()->all(),
            'groups'      => $this->groupMetadata(),
        ]);
    }

    /**
     * Persist a validated partial or full preferences payload and echo back the resulting blob.
     */
    public function save(SavePreferencesRequest $request): Response
    {
        return $this->persist($request->validated());
    }

    /**
     * Return the current preferences as a JSON payload suitable for download/re-import.
     */
    public function export(): Response
    {
        return Response::success(['preferences' => PluginSettings::make()->all()]);
    }

    /**
     * Validate an imported preferences blob with the same rules save() uses, then persist it.
     * Accepts both export()'s `{preferences: {...}}` envelope and a flat body so an exported file
     * round-trips as-is.
     */
    public function import(Request $request): Response
    {
        $payload = $request->all();
        if (isset($payload['preferences']) && \is_array($payload['preferences'])) {
            $payload = $payload['preferences'];
        }

        $request->make($payload, (new SavePreferencesRequest())->rules());

        if ($request->fails()) {
            return Response::error(['errors' => $request->errors()])->message(__('Validation failed', 'bit-smtp'));
        }

        return $this->persist($request->validated());
    }

    /**
     * Persist the given (already-validated) preference values, returning the full resulting blob.
     * LOG_SERVICE_MANAGED_KEYS are routed through LogService's canonical writers instead of the
     * blob-only fill(), so their continuity-marker/legacy-option side effects stay correct; every
     * other key is fill()->save()'d as before.
     *
     * @param array<string, mixed> $validated
     */
    private function persist(array $validated): Response
    {
        $this->applyLogServiceManagedKeys($validated);

        $remaining = Arr::except($validated, self::LOG_SERVICE_MANAGED_KEYS);
        if ($remaining !== []) {
            PluginSettings::make()->fill($remaining)->save();
        }

        return Response::success(['preferences' => PluginSettings::make()->all()]);
    }

    /**
     * Route logging_enabled/log_retention_days through LogService::setEnabled()/updateRetention()
     * when present in the payload. logging_enabled is only forwarded when it actually changes: both
     * to avoid needlessly resetting the logging-continuity marker and to match fill()'s existing
     * "unsent key is left untouched" semantics.
     *
     * @param array<string, mixed> $validated
     */
    private function applyLogServiceManagedKeys(array $validated): void
    {
        if (\array_key_exists('logging_enabled', $validated)) {
            $enabled = (bool) $validated['logging_enabled'];
            if ($enabled !== $this->logger()->isEnabled()) {
                $this->logger()->setEnabled($enabled);
            }
        }

        if (\array_key_exists('log_retention_days', $validated)) {
            $this->logger()->updateRetention((int) $validated['log_retention_days']);
        }
    }

    /**
     * The injected LogService, or the plugin's shared instance on first use.
     */
    private function logger(): LogService
    {
        return $this->logger ??= Plugin::instance()->logger();
    }

    /**
     * Group the schema's fields by their `group`, exposing enough per-field metadata (key, type,
     * default, and enum choices) for the UI to render grouped preference sections.
     *
     * @return array<int,array<string,mixed>>
     */
    private function groupMetadata(): array
    {
        $schema  = PluginSettings::schema();
        $byGroup = array_fill_keys($schema->groups(), []);

        foreach ($schema->fields() as $field) {
            $meta = [
                'key'     => $field->key(),
                'group'   => $field->group(),
                'type'    => $field->type(),
                'default' => $field->default(),
            ];

            if ($field->type() === SettingField::TYPE_ENUM) {
                $meta['choices'] = $field->choices();
            }

            $byGroup[$field->group()][] = $meta;
        }

        $groups = [];
        foreach ($byGroup as $name => $fields) {
            $groups[] = ['name' => $name, 'fields' => $fields];
        }

        return $groups;
    }
}
