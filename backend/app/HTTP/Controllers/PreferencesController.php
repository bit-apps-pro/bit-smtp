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
use BitApps\SMTP\Settings\PreferencesUpgrade;

/**
 * REST endpoints for reading, saving, exporting, and importing the plugin's global preferences.
 */
class PreferencesController
{
    /**
     * Preference keys managed via LogService (not blob-only) to preserve continuity/legacy-option side-effects.
     */
    private const LOG_SERVICE_MANAGED_KEYS = ['logging_enabled', 'log_retention_days'];

    /**
     * Lazily resolved so unmanagedkeys never force LogService construction.
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
     * Upgrade a pre-2.8 export, then validate with save() rules; accepts envelope or flat body for round-trip.
     */
    public function import(Request $request): Response
    {
        $payload = $request->all();
        if (isset($payload['preferences']) && \is_array($payload['preferences'])) {
            $payload = $payload['preferences'];
        }
        $payload = PreferencesUpgrade::upgrade($payload);

        $request->make($payload, (new SavePreferencesRequest())->rules());

        if ($request->fails()) {
            return Response::error(['errors' => $request->errors()])->message(__('Validation failed', 'bit-smtp'));
        }

        return $this->persist($request->validated());
    }

    /**
     * Persist validated values; route managed keys through LogService, others via fill()->save().
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
     * Route managed keys through LogService; only forward logging_enabled on actual change.
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
     * Group schema fields by group; expose metadata for UI preference sections.
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
