<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Auth;

use BitApps\SMTP\Mail\Auth\AwsSigV4Strategy;
use BitApps\SMTP\Mail\Aws\SigV4Signer;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Exceptions\AuthConfigException;
use BitApps\SMTP\Mail\Support\ApiRequest;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 *
 * @coversNothing
 */
class AwsSigV4StrategyTest extends BaseUnitTestCase
{
    private const ACCESS_KEY = 'AKIDEXAMPLE';

    private const SECRET_KEY = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

    public function testApplySignsRequestMatchingIndependentSigV4Recomputation(): void
    {
        $strategy = new AwsSigV4Strategy(new SigV4Signer(), 'ses');
        $request  = $this->request();

        $strategy->apply($request, $this->connection('us-east-1'));

        $baseHeaders = [
            'Host'                 => 'email.us-east-1.amazonaws.com',
            'Content-Type'         => 'application/json',
            'X-Amz-Content-Sha256' => hash('sha256', $request->body),
        ];
        $expected = SigV4Signer::sign(
            'POST',
            $request->url,
            'us-east-1',
            'ses',
            self::ACCESS_KEY,
            self::SECRET_KEY,
            $baseHeaders,
            $request->body,
            $request->headers['X-Amz-Date']
        );

        $this->assertSame($expected['Authorization'], $request->headers['Authorization']);
    }

    public function testApplySetsHostAndContentHashHeadersFromTheRequest(): void
    {
        $strategy = new AwsSigV4Strategy(new SigV4Signer(), 'ses');
        $request  = $this->request();

        $strategy->apply($request, $this->connection('us-east-1'));

        $this->assertSame('email.us-east-1.amazonaws.com', $request->headers['Host']);
        $this->assertSame(hash('sha256', $request->body), $request->headers['X-Amz-Content-Sha256']);
    }

    public function testTypeReturnsAwsSigv4(): void
    {
        $this->assertSame('aws_sigv4', (new AwsSigV4Strategy(new SigV4Signer(), 'ses'))->type());
    }

    #[DataProvider('malformedRegionProvider')]
    public function testApplyThrowsAndNeverSignsForMalformedRegion(string $region): void
    {
        $strategy = new AwsSigV4Strategy(new SigV4Signer(), 'ses');
        $request  = $this->request();

        $this->expectException(AuthConfigException::class);

        try {
            $strategy->apply($request, $this->connection($region));
        } finally {
            $this->assertArrayNotHasKey('Authorization', $request->headers);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function malformedRegionProvider(): array
    {
        return [
            'host injection characters' => ['evil.com#'],
            'path traversal characters' => ['a.b/'],
            'trailing newline'          => ["us-east-1\n"],
        ];
    }

    private function connection(string $region): Connection
    {
        return Connection::fromArray([
            'id'          => 'conn-1',
            'provider'    => 'amazon_ses',
            'kind'        => 'api',
            'settings'    => ['region' => $region, 'access_key' => self::ACCESS_KEY],
            'credentials' => ['secret_key' => ['source' => 'database', 'value' => self::SECRET_KEY]],
        ]);
    }

    private function request(): ApiRequest
    {
        return new ApiRequest('POST', 'https://email.us-east-1.amazonaws.com/v2/email/outbound-emails', '{"foo":"bar"}', 'application/json');
    }
}
