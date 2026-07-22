<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Webhook;

use BitApps\SMTP\Mail\Status\DeliveryStatus;

/**
 * Pure reducer: folds a log's delivery-event rows into one parent status + timestamp.
 * Per recipient the most recent event wins; for the parent the worst terminal outcome wins.
 */
final class DeliveryRollup
{
    /**
     * @param array<int,array<string,mixed>> $childRows
     *
     * @return array{status: ?string, updated_at: ?string}
     */
    public static function compute(array $childRows): array
    {
        if (empty($childRows)) {
            return ['status' => null, 'updated_at' => null];
        }

        return [
            'status'     => self::parentStatus(self::winnersPerRecipient($childRows)),
            'updated_at' => self::latestEffectiveTime($childRows),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $childRows
     *
     * @return array<int,array{status: string, terminal: bool}>
     */
    private static function winnersPerRecipient(array $childRows): array
    {
        $byRecipient = [];
        foreach ($childRows as $row) {
            $byRecipient[(string) ($row['recipient'] ?? '')][] = $row;
        }

        $winners = [];
        foreach ($byRecipient as $rows) {
            $winners[] = self::recipientWinner($rows);
        }

        return $winners;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array{status: string, terminal: bool}
     */
    private static function recipientWinner(array $rows): array
    {
        $terminalRows = array_filter($rows, static function ($row) {
            return !empty($row['terminal']);
        });

        $isTerminal = !empty($terminalRows);
        $candidates = $isTerminal ? $terminalRows : $rows;

        $best = null;
        foreach ($candidates as $row) {
            if ($best === null || self::rowOutranks($row, $best)) {
                $best = $row;
            }
        }

        return [
            'status'   => (string) ($best['status'] ?? ''),
            'terminal' => $isTerminal,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $incumbent
     */
    private static function rowOutranks(array $row, array $incumbent): bool
    {
        $rowTime       = self::effectiveTime($row);
        $incumbentTime = self::effectiveTime($incumbent);

        if ($rowTime !== $incumbentTime) {
            return $rowTime > $incumbentTime;
        }

        return self::severity((string) ($row['status'] ?? ''))
            > self::severity((string) ($incumbent['status'] ?? ''));
    }

    /**
     * @param array<int,array{status: string, terminal: bool}> $winners
     */
    private static function parentStatus(array $winners): ?string
    {
        $terminal = array_filter($winners, static function ($winner) {
            return $winner['terminal'];
        });
        $pool = empty($terminal) ? $winners : $terminal;

        $status       = null;
        $bestSeverity = -1;
        foreach ($pool as $winner) {
            $severity = self::severity($winner['status']);
            if ($severity > $bestSeverity) {
                $bestSeverity = $severity;
                $status       = $winner['status'];
            }
        }

        return $status;
    }

    /**
     * @param array<int,array<string,mixed>> $childRows
     */
    private static function latestEffectiveTime(array $childRows): ?string
    {
        $latest = '';
        foreach ($childRows as $row) {
            $time = self::effectiveTime($row);
            if ($time > $latest) {
                $latest = $time;
            }
        }

        return $latest === '' ? null : $latest;
    }

    /**
     * Recency key for a row: the provider timestamp, or created_at when the provider omitted one.
     * An empty string (no usable time) sorts earliest under lexicographic `Y-m-d H:i:s` comparison.
     *
     * @param array<string,mixed> $row
     */
    private static function effectiveTime(array $row): string
    {
        $occurredAt = $row['occurred_at'] ?? '';
        if ($occurredAt !== null && $occurredAt !== '') {
            return (string) $occurredAt;
        }

        $createdAt = $row['created_at'] ?? '';

        return $createdAt === null ? '' : (string) $createdAt;
    }

    private static function severity(string $status): int
    {
        return DeliveryStatus::severity($status);
    }
}
