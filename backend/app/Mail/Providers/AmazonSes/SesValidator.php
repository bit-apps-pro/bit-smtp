<?php

namespace BitApps\SMTP\Mail\Providers\AmazonSes;

use BitApps\SMTP\Mail\Contracts\ValidatorInterface;

class SesValidator implements ValidatorInterface
{
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
            // Region is interpolated into the signed request host (email.{region}.amazonaws.com);
            // reject anything but AWS's own region-name charset to prevent host injection.
            $errors['region'] = 'Region is invalid.';
        }

        return $errors;
    }
}
