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

    /**
     * @return Connection[]
     */
    public function resolveOrdered(MailSettings $settings): array
    {
        $enabledConnections = $settings->getConnections()->enabled();

        $ordered = [];
        $seenIds = [];

        $defaultConnectionId = $settings->getDefaultConnectionId();
        if ($defaultConnectionId !== '') {
            $defaultConnection = $enabledConnections->byId($defaultConnectionId);
            if ($defaultConnection !== null) {
                $ordered[]                     = $defaultConnection;
                $seenIds[$defaultConnectionId] = true;
            }
        }

        foreach ($settings->getFallbackConnectionIds() as $fallbackId) {
            $fallbackId = (string) $fallbackId;
            if (isset($seenIds[$fallbackId])) {
                continue;
            }

            $fallbackConnection = $enabledConnections->byId($fallbackId);
            if ($fallbackConnection === null) {
                continue;
            }

            $ordered[]              = $fallbackConnection;
            $seenIds[$fallbackId]   = true;
        }

        foreach ($enabledConnections->all() as $connection) {
            if (isset($seenIds[$connection->getId()])) {
                continue;
            }

            $ordered[]                       = $connection;
            $seenIds[$connection->getId()]   = true;
        }

        return $ordered;
    }
}
