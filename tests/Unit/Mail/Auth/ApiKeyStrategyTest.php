<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Auth;

use BitApps\SMTP\Mail\Auth\ApiKeyStrategy;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Support\ApiRequest;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class ApiKeyStrategyTest extends BaseUnitTestCase
{
    public function testApplySetsPostmarkServerTokenHeader(): void
    {
        $strategy = new ApiKeyStrategy([
            'headerName'    => 'X-Postmark-Server-Token',
            'valueTemplate' => '{api_key}',
        ]);
        $request = $this->request();

        $strategy->apply($request, $this->connection('tok'));

        $this->assertSame('tok', $request->headers['X-Postmark-Server-Token']);
    }

    public function testApplySetsZeptoMailAuthorizationHeader(): void
    {
        $strategy = new ApiKeyStrategy([
            'headerName'    => 'Authorization',
            'valueTemplate' => 'Zoho-enczapikey {api_key}',
        ]);
        $request = $this->request();

        $strategy->apply($request, $this->connection('tok'));

        $this->assertSame('Zoho-enczapikey tok', $request->headers['Authorization']);
    }

    public function testApplySetsSparkPostAuthorizationHeader(): void
    {
        $strategy = new ApiKeyStrategy([
            'headerName'    => 'Authorization',
            'valueTemplate' => '{api_key}',
        ]);
        $request = $this->request();

        $strategy->apply($request, $this->connection('sp-key'));

        $this->assertSame('sp-key', $request->headers['Authorization']);
    }

    public function testApplySetsBrevoApiKeyHeader(): void
    {
        $strategy = new ApiKeyStrategy([
            'headerName'    => 'api-key',
            'valueTemplate' => '{api_key}',
        ]);
        $request = $this->request();

        $strategy->apply($request, $this->connection('brevo-key'));

        $this->assertSame('brevo-key', $request->headers['api-key']);
    }

    public function testTypeReturnsApiKey(): void
    {
        $this->assertSame('api_key', (new ApiKeyStrategy())->type());
    }

    private function connection(string $apiKey): Connection
    {
        return Connection::fromArray([
            'id'          => 'conn-1',
            'provider'    => 'postmark',
            'kind'        => 'api',
            'credentials' => ['api_key' => ['source' => 'db', 'value' => $apiKey]],
        ]);
    }

    private function request(): ApiRequest
    {
        return new ApiRequest('POST', 'https://example.test/send', '{}', 'application/json');
    }
}
