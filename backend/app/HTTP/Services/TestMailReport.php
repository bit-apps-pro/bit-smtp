<?php

namespace BitApps\SMTP\HTTP\Services;

\defined('ABSPATH') || exit();

/**
 * Outcome of an admin test send: per-connection attempts, the connection that sent it, and debug output.
 */
final class TestMailReport
{
    /**
     * @var array<int,array{connection: string, status: string, error: ?string}>
     */
    private array $attempts;

    /**
     * @var array<int,string>
     */
    private array $debug;

    private ?string $sentVia;

    /**
     * @param array<int,array{connection: string, status: string, error: ?string}> $attempts
     * @param array<int,string>                                                    $debug
     */
    private function __construct(array $attempts, array $debug, ?string $sentVia)
    {
        $this->attempts = $attempts;
        $this->debug    = $debug;
        $this->sentVia  = $sentVia;
    }

    /**
     * Build a report from the bridge's attempt trail; the last attempt is the sender only when the send succeeded.
     *
     * @param array<int,array{connection: string, status: string, error: ?string}> $attempts
     * @param array<int,string>                                                    $debug
     */
    public static function fromAttempts(bool $succeeded, array $attempts, array $debug): self
    {
        $attempts = array_values($attempts);
        $last     = $attempts === [] ? null : $attempts[\count($attempts) - 1];
        $sentVia  = $succeeded && $last !== null ? (string) $last['connection'] : null;

        return new self($attempts, self::cleanDebug($debug), $sentVia);
    }

    /**
     * Whether a backup connection sent the mail after an earlier connection failed.
     */
    public function failedOver(): bool
    {
        return $this->sentVia !== null && \count($this->attempts) > 1;
    }

    /**
     * Response payload for the Test page.
     *
     * @return array{attempts: array<int,array{connection: string, status: string, error: ?string}>, debug: array<int,string>, sent_via: ?string, failed_over: bool}
     */
    public function toArray(): array
    {
        return [
            'attempts'    => $this->attempts,
            'debug'       => $this->debug,
            'sent_via'    => $this->sentVia,
            'failed_over' => $this->failedOver(),
        ];
    }

    /**
     * Trim transport debug lines and drop blank ones.
     *
     * @param array<int,string> $debug
     *
     * @return array<int,string>
     */
    private static function cleanDebug(array $debug): array
    {
        $lines = array_map(static fn ($line): string => trim((string) $line), $debug);

        return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
    }
}
