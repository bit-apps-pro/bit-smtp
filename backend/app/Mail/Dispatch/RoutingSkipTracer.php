<?php

namespace BitApps\SMTP\Mail\Dispatch;

use BitApps\SMTP\Mail\Config\MailSettings;
use BitApps\SMTP\Mail\Connections\Connection;

/**
 * Traces the priority-chain connections a send bypassed (never attempted) and why, so the log detail
 * can explain a matched or default connection being skipped. Pure given the priority chain and the
 * sendable set — the bridge computes the chain via RoutingPlanner and passes it in.
 */
final class RoutingSkipTracer
{
    private ConnectionSendability $sendability;

    public function __construct(ConnectionSendability $sendability)
    {
        $this->sendability = $sendability;
    }

    /**
     * The priority-chain connections not in $sendable (never attempted), each tagged with why it was
     * dropped so the log can explain a bypassed match/default. Returns [] when the whole chain sends.
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
     * Keep only the skips positioned ahead of the connection that actually sent (or was last tried); a
     * lower-priority fallback never reached is dropped. When the sender isn't in the chain, keep all.
     *
     * @param array<int,array{connection: string, connection_id: string, reason: string}> $skips
     * @param string[]                                                                     $chainIds
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
     * Classify why a prioritized candidate connection cannot be attempted for this send, as a short,
     * stable reason code the log/UI can display. $connection is null when the id no longer resolves to
     * a configured connection.
     */
    public function skipReason(?Connection $connection): string
    {
        return match (true) {
            $connection === null => 'deleted',
            !$connection->isEnabled() => 'disabled',
            !$this->sendability->isSendable($connection) => 'incomplete',
            default => '',
        };
    }
}
