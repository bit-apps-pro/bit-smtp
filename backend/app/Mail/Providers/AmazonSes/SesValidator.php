<?php

namespace BitApps\SMTP\Mail\Providers\AmazonSes;

use BitApps\SMTP\Mail\Contracts\ValidatorInterface;

class SesValidator implements ValidatorInterface
{
    /**
     * Validate SES settings; region charset restricted to prevent host injection in signed request.
     *
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $credentials
     *
     * @return array<string,string>
     */
    public function validate(array $settings, array $credentials): array
    {
        $errors = [];

        if (trim((string) ($settings['access_key'] ?? '')) === '') {
            $errors['access_key'] = __('Access Key ID is required.', 'bit-smtp');
        }

        if (trim((string) ($credentials['secret_key'] ?? '')) === '') {
            $errors['secret_key'] = __('Secret Access Key is required.', 'bit-smtp');
        }

        $region = trim((string) ($settings['region'] ?? ''));
        if ($region === '') {
            $errors['region'] = __('Region is required.', 'bit-smtp');
        } elseif (!preg_match('/^[a-z0-9-]+$/', $region)) {
            $errors['region'] = __('Region is invalid.', 'bit-smtp');
        }

        return $errors;
    }
}
