<?php

namespace BitApps\SMTP\Mail\Validation;

use BitApps\SMTP\Mail\Contracts\ValidatorInterface;

/**
 * Generic validator: check provider's declared required fields are present, driven by fields() metadata.
 */
final class RequiredFieldsValidator implements ValidatorInterface
{
    /**
     * @var array<int,array<string,mixed>>
     */
    private array $fields;

    public function __construct(array $fields)
    {
        $this->fields = $fields;
    }

    public function validate(array $settings, array $credentials): array
    {
        $errors = [];

        foreach ($this->fields as $field) {
            if (($field['type'] ?? null) === 'oauth' || !$this->isRequired($field, $settings)) {
                continue;
            }

            $key    = $field['key'];
            $source = !empty($field['secret']) ? $credentials : $settings;

            if (trim((string) ($source[$key] ?? '')) === '') {
                // translators: %s: field label
                $errors[$key] = \sprintf(__('%s is required.', 'bit-smtp'), ucfirst((string) ($field['label'] ?? $key)));
            }
        }

        return $errors;
    }

    /**
     * Whether a field must be filled: always when `required`, or while its `requiredWhen` setting holds the given value (booleans compared by truthiness, like the transports).
     *
     * @param array<string,mixed> $field
     * @param array<string,mixed> $settings
     */
    private function isRequired(array $field, array $settings): bool
    {
        if (!empty($field['required'])) {
            return true;
        }

        $condition = $field['requiredWhen'] ?? null;
        if (!\is_array($condition) || !isset($condition['field'])) {
            return false;
        }

        $actual   = $settings[$condition['field']] ?? null;
        $expected = $condition['value']            ?? null;

        return \is_bool($expected) ? !empty($actual) === $expected : $actual === $expected;
    }
}
