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
     * Priority order used by the dispatch loop: an optional routed connection first, then the
     * configured default, the listed fallbacks, and finally any remaining enabled connections —
     * de-duplicated. Passing no $preferredId yields the exact pre-routing order.
     *
     * @return Connection[]
     */
    public function resolveOrdered(MailSettings $settings, ?string $preferredId = null): array
    {
        $enabledConnections = $settings->getConnections()->enabled();

        $ordered = [];
        $seenIds = [];

        if ($preferredId !== null && $preferredId !== '') {
            $preferredConnection = $enabledConnections->byId($preferredId);
            if ($preferredConnection !== null) {
                $ordered[]             = $preferredConnection;
                $seenIds[$preferredId] = true;
            }
        }

        $defaultConnectionId = $settings->getDefaultConnectionId();
        if ($defaultConnectionId !== '' && !isset($seenIds[$defaultConnectionId])) {
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
