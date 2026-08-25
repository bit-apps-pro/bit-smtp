<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Credentials;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\CredentialResolverInterface;
use BitApps\SMTP\Mail\Credentials\ConnectionSecretResolver;
use BitApps\SMTP\Mail\Credentials\ConstantReader;
use BitApps\SMTP\Mail\Credentials\Credential;
use BitApps\SMTP\Mail\Credentials\WpConfigCredentialSource;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Mockery;

/**
 * @internal
 *
 * @coversNothing
 */
class ConnectionSecretResolverTest extends BaseUnitTestCase
{
    public function testWpConfigConstantOverridesStoredValueAndBypassesStoredResolver(): void
    {
        $connection = $this->connection(['password' => ['source' => 'database', 'value' => 'db-secret']]);

        // never(): the stored path is the only place CredentialCipher::decrypt lives, so proving it is
        // untouched proves the override is returned verbatim, never run through the cipher.
        $stored = Mockery::mock(CredentialResolverInterface::class);
        $stored->shouldReceive('resolve')->never();

        $resolver = new ConnectionSecretResolver($stored, $this->wpConfig([
            'BIT_SMTP_CONN_1_PASSWORD' => 'from-wp-config',
        ]));

        $this->assertSame('from-wp-config', $resolver->resolve($connection, 'password'));
    }

    public function testFallsBackToStoredResolverWhenConstantUndefined(): void
    {
        $connection = $this->connection(['password' => ['source' => 'database', 'value' => 'db-secret']]);

        $stored = Mockery::mock(CredentialResolverInterface::class);
        $stored->shouldReceive('resolve')
            ->once()
            ->with(Mockery::on(static fn (Credential $c): bool => $c->getValue() === 'db-secret' && $c->getSource() === 'database'))
            ->andReturn('db-secret');

        $resolver = new ConnectionSecretResolver($stored, $this->wpConfig([]));

        $this->assertSame('db-secret', $resolver->resolve($connection, 'password'));
    }

    public function testFallsBackToStoredResolverWhenConstantIsEmpty(): void
    {
        $connection = $this->connection(['password' => ['source' => 'database', 'value' => 'db-secret']]);

        $stored = Mockery::mock(CredentialResolverInterface::class);
        $stored->shouldReceive('resolve')->once()->andReturn('db-secret');

        // Defined-but-empty constant must not lock out the DB credential.
        $resolver = new ConnectionSecretResolver($stored, $this->wpConfig([
            'BIT_SMTP_CONN_1_PASSWORD' => '',
        ]));

        $this->assertSame('db-secret', $resolver->resolve($connection, 'password'));
    }

    public function testReturnsNullWhenCredentialKeyMissingAndNoConstant(): void
    {
        $connection = $this->connection([]);

        $stored = Mockery::mock(CredentialResolverInterface::class);
        $stored->shouldReceive('resolve')->never();

        $resolver = new ConnectionSecretResolver($stored, $this->wpConfig([]));

        $this->assertNull($resolver->resolve($connection, 'password'));
    }

    /**
     * Build an SMTP connection (id "conn_1") carrying the given credentials map.
     *
     * @param array<string,mixed> $credentials
     */
    private function connection(array $credentials): Connection
    {
        return Connection::fromArray([
            'id'          => 'conn_1',
            'provider'    => 'other_smtp',
            'kind'        => 'smtp',
            'credentials' => $credentials,
        ]);
    }

    /**
     * A WpConfigCredentialSource whose constants come from an in-memory map instead of real defines.
     *
     * @param array<string,string> $constants
     */
    private function wpConfig(array $constants): WpConfigCredentialSource
    {
        $reader = new class($constants) implements ConstantReader {
            /**
             * @var array<string,string>
             */
            private array $constants;

            /**
             * @param array<string,string> $constants
             */
            public function __construct(array $constants)
            {
                $this->constants = $constants;
            }

            public function read(string $name): ?string
            {
                return $this->constants[$name] ?? null;
            }
        };

        return new WpConfigCredentialSource($reader);
    }
}
