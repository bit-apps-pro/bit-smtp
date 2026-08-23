<?php

namespace BitApps\SMTP\HTTP\Requests;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;
use BitApps\SMTP\Deps\BitApps\WPKit\Settings\SettingField;
use BitApps\SMTP\HTTP\Requests\Rules\InRule;
use BitApps\SMTP\Settings\PluginSettings;

/**
 * Validates a preferences save/import payload against the PluginSettings schema field types.
 */
class SavePreferencesRequest extends Request
{
    /**
     * Build one nullable rule set per schema field, keyed by field key, so any key the client sends
     * that isn't in the schema is dropped from validated() rather than persisted.
     *
     * @return array<string, array<int,mixed>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (PluginSettings::schema()->fields() as $field) {
            $rules[$field->key()] = $this->rulesForField($field);
        }

        return $rules;
    }

    /**
     * Map a schema field's type to its wp-validator rule set.
     *
     * @return array<int,mixed>
     */
    private function rulesForField(SettingField $field): array
    {
        $rules = ['nullable'];

        switch ($field->type()) {
            case SettingField::TYPE_BOOL:
                $rules[] = 'boolean';

                break;

            case SettingField::TYPE_INT:
                $rules[] = 'integer';
                // The schema's own sanitizer clamps this field to 1..200; reject out-of-range
                // input explicitly here instead of letting it silently clamp.
                if ($field->key() === 'log_retention_days') {
                    $rules[] = 'between:1,200';
                }

                break;

            case SettingField::TYPE_FLOAT:
                $rules[] = 'numeric';

                break;

            case SettingField::TYPE_ARRAY:
                $rules[] = 'array';

                break;

            case SettingField::TYPE_ENUM:
                $rules[] = 'string';
                $rules[] = new InRule($field->choices() ?? []);

                break;

            default:
                $rules[] = 'string';
        }

        return $rules;
    }
}
