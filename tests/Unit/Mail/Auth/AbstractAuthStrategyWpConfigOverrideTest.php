<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Auth;

use BitApps\SMTP\Mail\Auth\AbstractAuthStrategy;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\AuthStrategyInterface;
use BitApps\SMTP\Mail\Credentials\ConstantReader;
use BitApps\SMTP\Mail\Credentials\WpConfigCredentialSource;
use BitApps\SMTP\Mail\Support\ApiRequest;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class AbstractAuthStrategyWpConfigOverrideTest extends BaseUnitTestCase
{
    public function testAWpConfigConstantOverridesTheStoredApiKey(): void
    {
        $strategy = $this->strategy($this->reader(['BIT_SMTP_CONN_1_API_KEY' => 'from-wp-config']));
        $conn     = $this->connection(['api_key' => ['source' => 'database', 'value' => 'from-db']]);

        self::assertSame('from-wp-config', $strategy->exposeSecret($conn, 'api_key'));
    }

    public function testFallsBackToTheStoredValueWhenNoConstantIsDefined(): void
    {
        $strategy = $this->strategy($this->reader([]));
        $conn     = $this->connection(['api_key' => ['source' => 'database', 'value' => 'from-db']]);

        self::assertSame('from-db', $strategy->exposeSecret($conn, 'api_key'));
    }

    public function testInterpolateUsesTheConstantForACredentialKey(): void
    {
        $strategy = $this->strategy($this->reader(['BIT_SMTP_CONN_1_API_KEY' => 'from-wp-config']));
        $conn     = $this->connection(['api_key' => ['source' => 'database', 'value' => 'from-db']]);

        self::assertSame('Bearer from-wp-config', $strategy->exposeInterpolate('Bearer {api_key}', $conn));
    }

    /**
     * @param array<string,string> $constants
     */
    private function reader(array $constants): ConstantReader
    {
        return new class($constants) implements ConstantReader {
            /**
             * @param array<string,string> $constants
             */
            public function __construct(private array $constants)
            {
            }

            public function read(string $name): ?string
            {
                return $this->constants[$name] ?? null;
            }
        };
    }

    private function strategy(ConstantReader $reader): object
    {
        return new class([], new WpConfigCredentialSource($reader)) extends AbstractAuthStrategy implements AuthStrategyInterface {
            public function apply(ApiRequest $request, Connection $connection): void
            {
            }

            public function type(): string
            {
                return 'test';
            }

            public function exposeSecret(Connection $connection, string $key): string
            {
                return $this->secret($connection, $key);
            }

            public function exposeInterpolate(string $template, Connection $connection): string
            {
                return $this->interpolate($template, $connection);
            }
        };
    }

    /**
     * @param array<string,mixed> $credentials
     */
    private function connection(array $credentials): Connection
    {
        return Connection::fromArray([
            'id'          => 'conn_1',
            'provider'    => 'brevo',
            'kind'        => 'api',
            'credentials' => $credentials,
        ]);
    }
}
