<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Auth;

use BitApps\SMTP\Mail\Auth\BasicAuthStrategy;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Support\ApiRequest;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class BasicAuthStrategyTest extends BaseUnitTestCase
{
    public function testApplySetsBasicAuthorizationHeaderForMailgun(): void
    {
        $strategy = new BasicAuthStrategy([
            'userExpr' => 'api',
            'passExpr' => '{api_key}',
        ]);
        $connection = Connection::fromArray([
            'id'          => 'conn-1',
            'provider'    => 'mailgun',
            'kind'        => 'api',
            'credentials' => ['api_key' => ['source' => 'db', 'value' => 'key']],
        ]);
        $request = $this->request();

        $strategy->apply($request, $connection);

        $this->assertSame('Basic ' . base64_encode('api:key'), $request->headers['Authorization']);
    }

    public function testApplySetsBasicAuthorizationHeaderForMailjetTwoSecretCredentials(): void
    {
        $strategy = new BasicAuthStrategy([
            'userExpr' => '{api_key}',
            'passExpr' => '{secret_key}',
        ]);
        $connection = Connection::fromArray([
            'id'          => 'conn-1',
            'provider'    => 'mailjet',
            'kind'        => 'api',
            'credentials' => [
                'api_key'    => ['source' => 'db', 'value' => 'pubkey'],
                'secret_key' => ['source' => 'db', 'value' => 'secretkey'],
            ],
        ]);
        $request = $this->request();

        $strategy->apply($request, $connection);

        $this->assertSame('Basic ' . base64_encode('pubkey:secretkey'), $request->headers['Authorization']);
    }

    public function testTypeReturnsBasic(): void
    {
        $this->assertSame('basic', (new BasicAuthStrategy())->type());
    }

    private function request(): ApiRequest
    {
        return new ApiRequest('POST', 'https://example.test/send', '{}', 'application/json');
    }
}
