<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Http;

use BitApps\SMTP\Mail\Http\ApiResponse;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 *
 * @coversNothing
 */
class ApiResponseTest extends BaseUnitTestCase
{
    #[DataProvider('okStatusProvider')]
    public function testIsOkForTwoXxStatuses(int $status, bool $expected): void
    {
        $response = new ApiResponse($status, []);

        $this->assertSame($expected, $response->isOk());
    }

    public static function okStatusProvider(): array
    {
        return [
            '200 ok'                  => [200, true],
            '202 accepted'            => [202, true],
            '299 edge of range'       => [299, true],
            '199 below range'         => [199, false],
            '300 redirect'            => [300, false],
            '400 bad request'         => [400, false],
            '500 server error'        => [500, false],
            '0 network failure'       => [0, false],
        ];
    }

    public function testGettersReturnConstructorValues(): void
    {
        $response = new ApiResponse(202, ['id' => 'abc'], ['Content-Type' => 'application/json']);

        $this->assertSame(202, $response->getStatus());
        $this->assertSame(['id' => 'abc'], $response->getBody());
        $this->assertSame(['Content-Type' => 'application/json'], $response->getHeaders());
    }

    public function testGetHeaderIsCaseInsensitive(): void
    {
        $response = new ApiResponse(200, [], ['Content-Type' => 'application/json']);

        $this->assertSame('application/json', $response->getHeader('content-type'));
    }

    public function testGetHeaderReturnsNullWhenMissing(): void
    {
        $response = new ApiResponse(200, [], []);

        $this->assertNull($response->getHeader('content-type'));
    }

    public function testBodyMayBeAString(): void
    {
        $response = new ApiResponse(500, 'internal server error');

        $this->assertSame('internal server error', $response->getBody());
    }
}
