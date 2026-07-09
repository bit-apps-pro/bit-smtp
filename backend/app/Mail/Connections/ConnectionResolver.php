<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Connections;

use BitApps\SMTP\Mail\Config\MailSettings;

final class ConnectionResolver
{
    public function resolve(MailSettings $settings): ?Connection
    {
        $defaultConnectionId = $settings->getDefaultConnectionId();

        if ($defaultConnectionId !== '') {
            $defaultConnection = $settings->getConnections()->byId($defaultConnectionId);
            if ($defaultConnection !== null && $defaultConnection->isEnabled()) {
                return $defaultConnection;
            }
        }

        return $settings->getConnections()->enabled()->first();
    }
}
