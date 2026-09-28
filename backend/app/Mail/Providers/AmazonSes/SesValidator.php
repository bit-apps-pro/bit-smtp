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
            $errors['access_key'] = 'Access Key ID is required.';
        }

        if (trim((string) ($credentials['secret_key'] ?? '')) === '') {
            $errors['secret_key'] = 'Secret Access Key is required.';
        }

        $region = trim((string) ($settings['region'] ?? ''));
        if ($region === '') {
            $errors['region'] = 'Region is required.';
        } elseif (!preg_match('/^[a-z0-9-]+$/', $region)) {
            $errors['region'] = 'Region is invalid.';
        }

        return $errors;
    }
}
