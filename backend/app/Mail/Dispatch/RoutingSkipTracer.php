<?php

namespace BitApps\SMTP\Mail\Dispatch;

use BitApps\SMTP\Mail\Config\MailSettings;
use BitApps\SMTP\Mail\Connections\Connection;

/**
 * Trace bypassed priority-chain connections and reasons for log detail.
 */
final class RoutingSkipTracer
{
    private ConnectionSendability $sendability;

    public function __construct(ConnectionSendability $sendability)
    {
        $this->sendability = $sendability;
    }

    /**
     * Priority-chain connections not in $sendable, tagged with why dropped (for log detail).
     *
     * @param string[]     $chainIds priority-order connection ids the router would try
     * @param Connection[] $sendable connections that will actually be attempted, in priority order
     *
     * @return array<int,array{connection: string, connection_id: string, reason: string}>
     */
    public function routingSkips(MailSettings $settings, array $chainIds, array $sendable): array
    {
        $sendableIds = array_map(static fn (Connection $c): string => $c->getId(), $sendable);
        $connections = $settings->getConnections();

        $skips = [];
        foreach ($chainIds as $id) {
            if (\in_array($id, $sendableIds, true)) {
                continue;
            }

            $connection = $connections->byId($id);
            $skips[]    = [
                'connection'    => $connection !== null ? $connection->label() : $id,
                'connection_id' => $id,
                'reason'        => $this->skipReason($connection),
            ];
        }

        return $skips;
    }

    /**
     * Keep only skips positioned ahead of the connection that actually sent; drop unreached fallbacks.
     *
     * @param array<int,array{connection: string, connection_id: string, reason: string}> $skips
     * @param string[]                                                                    $chainIds
     *
     * @return array<int,array{connection: string, connection_id: string, reason: string}>
     */
    public function skipsAheadOf(?Connection $used, array $skips, array $chainIds): array
    {
        if ($skips === []) {
            return [];
        }

        $usedPosition = $used !== null ? array_search($used->getId(), $chainIds, true) : false;
        if ($usedPosition === false) {
            return array_values($skips);
        }

        return array_values(array_filter($skips, static function (array $skip) use ($chainIds, $usedPosition): bool {
            $position = array_search($skip['connection_id'], $chainIds, true);

            return $position !== false && $position < $usedPosition;
        }));
    }

    /**
     * Classify why connection cannot be attempted; null means id is no longer configured.
     */
    public function skipReason(?Connection $connection): string
    {
        return match (true) {
            $connection === null                         => 'deleted',
            !$connection->isEnabled()                    => 'disabled',
            !$this->sendability->isSendable($connection) => 'incomplete',
            default                                      => '',
        };
    }
}
