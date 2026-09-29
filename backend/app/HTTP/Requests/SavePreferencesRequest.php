<?php

namespace BitApps\SMTP\HTTP\Requests;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;
use BitApps\SMTP\Deps\BitApps\WPKit\Settings\SettingField;
use BitApps\SMTP\HTTP\Requests\Rules\EachInRule;
use BitApps\SMTP\HTTP\Requests\Rules\InRule;
use BitApps\SMTP\Mail\Dispatch\FailureCategory;
use BitApps\SMTP\Mail\Notifications\AlertEvents;
use BitApps\SMTP\Settings\PluginSettings;

/**
 * Validates a preferences save/import payload against the PluginSettings schema field types.
 */
class SavePreferencesRequest extends Request
{
    /**
     * Integer preferences bounded at the request boundary, mapped to their inclusive [min, max] range.
     */
    private const INT_RANGES = [
        'log_retention_days'   => PluginSettings::LOG_RETENTION_DAYS_RANGE,
        'send_timeout_seconds' => PluginSettings::SEND_TIMEOUT_SECONDS_RANGE,
        'retry_max_attempts'   => PluginSettings::RETRY_MAX_ATTEMPTS_RANGE,
    ];

    /**
     * Build nullable rule set per schema field; drop unknown keys from validated().
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
                if (isset(self::INT_RANGES[$field->key()])) {
                    $rules[] = 'between:' . implode(',', self::INT_RANGES[$field->key()]);
                }

                break;

            case SettingField::TYPE_FLOAT:
                $rules[] = 'numeric';

                break;

            case SettingField::TYPE_ARRAY:
                $rules[] = 'array';
                if ($field->key() === 'notify_events') {
                    $rules[] = new EachInRule(AlertEvents::keys());
                } elseif ($field->key() === 'retry_on_classes') {
                    $rules[] = new EachInRule(FailureCategory::retryableClasses());
                }

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
