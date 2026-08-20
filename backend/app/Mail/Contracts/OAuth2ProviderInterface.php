<?php

namespace BitApps\SMTP\Mail\Contracts;

use BitApps\SMTP\Mail\Connections\Connection;

interface OAuth2ProviderInterface
{
    /**
     * Microsoft's authorize endpoint is per-tenant; Google's is a fixed constant. Providers
     * that don't need the connection are free to ignore it.
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
