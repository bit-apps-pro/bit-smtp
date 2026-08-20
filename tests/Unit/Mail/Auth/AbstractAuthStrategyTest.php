<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Auth;

use BitApps\SMTP\Mail\Auth\AbstractAuthStrategy;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Exceptions\AuthConfigException;
use BitApps\SMTP\Mail\Support\ApiRequest;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class AbstractAuthStrategyTest extends BaseUnitTestCase
{
    public function testSecretResolvesValueFromCredentials(): void
    {
        $strategy = new StubAuthStrategy();

        $this->assertSame('zoho-secret', $strategy->publicSecret($this->connection(), 'api_key'));
    }

    public function testSecretReturnsEmptyStringWhenCredentialMissing(): void
    {
        $strategy = new StubAuthStrategy();

        $this->assertSame('', $strategy->publicSecret($this->connection(), 'missing_key'));
    }

    public function testRequireSecretReturnsValueWhenPresent(): void
    {
        $strategy = new StubAuthStrategy();

        $this->assertSame('zoho-secret', $strategy->publicRequireSecret($this->connection(), 'api_key'));
    }

    public function testRequireSecretThrowsAuthConfigExceptionWhenMissing(): void
    {
        $strategy = new StubAuthStrategy();

        $this->expectException(AuthConfigException::class);
        $this->expectExceptionMessage('Missing credential: missing_key');

        $strategy->publicRequireSecret($this->connection(), 'missing_key');
    }

    public function testInterpolateFillsTokenFromCredentials(): void
    {
        $strategy = new StubAuthStrategy();

        $result = $strategy->publicInterpolate('Zoho-enczapikey {api_key}', $this->connection());

        $this->assertSame('Zoho-enczapikey zoho-secret', $result);
    }

    public function testInterpolateFillsTokenFromSettingsWhenNotACredential(): void
    {
        $strategy = new StubAuthStrategy();

        $result = $strategy->publicInterpolate('region={region}', $this->connection());

        $this->assertSame('region=eu', $result);
    }

    public function testInterpolateLeavesUnknownTokensEmpty(): void
    {
        $strategy = new StubAuthStrategy();

        $result = $strategy->publicInterpolate('token={unknown}', $this->connection());

        $this->assertSame('token=', $result);
    }

    public function testConstructorStoresConfig(): void
    {
        $strategy = new StubAuthStrategy(['foo' => 'bar']);

        $this->assertSame(['foo' => 'bar'], $strategy->publicConfig());
    }

    private function connection(): Connection
    {
        return Connection::fromArray([
            'id'          => 'conn-1',
            'provider'    => 'zoho',
            'kind'        => 'api',
            'settings'    => ['region' => 'eu'],
            'credentials' => ['api_key' => ['source' => 'db', 'value' => 'zoho-secret']],
        ]);
    }
}

class StubAuthStrategy extends AbstractAuthStrategy
{
    public function apply(ApiRequest $request, Connection $connection): void
    {
        $request->setHeader('Authorization', $this->requireSecret($connection, 'api_key'));
    }

    public function type(): string
    {
        return 'stub';
    }

    public function publicSecret(Connection $connection, string $key): string
    {
        return $this->secret($connection, $key);
    }

    public function publicRequireSecret(Connection $connection, string $key): string
    {
        return $this->requireSecret($connection, $key);
    }

    public function publicInterpolate(string $template, Connection $connection): string
    {
        return $this->interpolate($template, $connection);
    }

    public function publicConfig(): array
    {
        return $this->config;
    }
}
