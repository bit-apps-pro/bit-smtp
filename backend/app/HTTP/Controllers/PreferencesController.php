<?php

namespace BitApps\SMTP\HTTP\Controllers;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\Deps\BitApps\WPKit\Settings\SettingField;
use BitApps\SMTP\HTTP\Requests\SavePreferencesRequest;
use BitApps\SMTP\Settings\PluginSettings;

/**
 * REST endpoints for reading, saving, exporting, and importing the plugin's global preferences.
 */
class PreferencesController
{
    /**
     * Return the current preferences plus schema-derived field metadata for the settings UI.
     */
    public function index()
    {
        return Response::success([
            'preferences' => PluginSettings::make()->all(),
            'groups'      => $this->groupMetadata(),
        ]);
    }

    /**
     * Persist a validated partial or full preferences payload and echo back the resulting blob.
     */
    public function save(SavePreferencesRequest $request)
    {
        return $this->persist($request->validated());
    }

    /**
     * Return the current preferences as a JSON payload suitable for download/re-import.
     */
    public function export()
    {
        return Response::success(['preferences' => PluginSettings::make()->all()]);
    }

    /**
     * Validate an imported preferences blob with the same rules save() uses, then persist it.
     * Accepts both export()'s `{preferences: {...}}` envelope and a flat body so an exported file
     * round-trips as-is.
     */
    public function import(Request $request)
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
     * Fill and persist the given (already-validated) preference values, returning the full blob.
     */
    private function persist(array $validated): Response
    {
        $settings = PluginSettings::make()->fill($validated);
        $settings->save();

        return Response::success(['preferences' => $settings->all()]);
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
