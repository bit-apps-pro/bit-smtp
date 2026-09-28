<?php

namespace BitApps\SMTP\Mail\Contracts;

use BitApps\SMTP\Mail\Connections\Connection;

interface OAuth2ProviderInterface
{
    /**
     * Authorization endpoint; per-tenant for Microsoft, fixed for Google.
     */
    public function authUrl(Connection $connection): string;

    public function tokenUrl(Connection $connection): string;

    /**
     * @return string[]
     */
    public function scopes(): array;

    /**
     * Extra query params merged into the consent URL, e.g. Google's offline-access params.
     *
     * @return array<string,string>
     */
    public function extraAuthParams(): array;
}
