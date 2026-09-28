<?php

namespace BitApps\SMTP\Mail\Credentials;

use BitApps\SMTP\Mail\Contracts\CredentialResolverInterface;

class DatabaseCredentialResolver implements CredentialResolverInterface
{
    public function supports(string $source): bool
    {
        return $source === 'database';
    }

    /**
     * Resolves a database credential; treats only null/'' as absent so a secret of "0" is valid.
     */
    public function resolve(Credential $credential): ?string
    {
        if (!$this->supports($credential->getSource())) {
            return null;
        }

        $value = $credential->getValue();

        return ($value === null || $value === '') ? null : $value;
    }
}
