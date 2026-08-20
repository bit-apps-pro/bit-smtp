<?php

namespace BitApps\SMTP\Mail\Validation;

use BitApps\SMTP\Mail\Contracts\ValidatorInterface;

/**
 * Generic validator that checks a provider's declared required fields are present,
 * driven entirely by that provider's fields() metadata rather than per-provider code.
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
            if (($field['type'] ?? null) === 'oauth' || empty($field['required'])) {
                continue;
            }

            $key    = $field['key'];
            $source = !empty($field['secret']) ? $credentials : $settings;

            if (trim((string) ($source[$key] ?? '')) === '') {
                $errors[$key] = ucfirst((string) ($field['label'] ?? $key)) . ' is required.';
            }
        }

        return $errors;
    }
}
