<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Mail\OAuth;

use BitApps\SMTP\Mail\OAuth\OAuthFailureReason;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 *
 * @coversNothing
 */
final class OAuthFailureReasonTest extends BaseUnitTestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function providerErrors(): iterable
    {
        yield 'access_denied'         => ['access_denied', OAuthFailureReason::ACCESS_DENIED];
        yield 'consent_required'      => ['consent_required', OAuthFailureReason::ACCESS_DENIED];
        yield 'invalid_client'        => ['invalid_client', OAuthFailureReason::INVALID_CLIENT];
        yield 'unauthorized_client'   => ['unauthorized_client', OAuthFailureReason::INVALID_CLIENT];
        yield 'invalid_grant'         => ['invalid_grant', OAuthFailureReason::INVALID_GRANT];
        yield 'redirect_uri_mismatch' => ['redirect_uri_mismatch', OAuthFailureReason::REDIRECT_URI_MISMATCH];
        yield 'unrecognised'          => ['<script>alert(1)</script>', OAuthFailureReason::UNKNOWN];
    }

    #[DataProvider('providerErrors')]
    public function testFromProviderErrorMapsToSafeCodes(string $error, string $expected): void
    {
        $this->assertSame($expected, OAuthFailureReason::fromProviderError($error));
    }

    public function testFromTokenResponseReadsTheErrorField(): void
    {
        $this->assertSame(OAuthFailureReason::INVALID_CLIENT, OAuthFailureReason::fromTokenResponse(['error' => 'invalid_client']));
    }

    public function testFromTokenResponseFallsBackToTokenExchangeFailed(): void
    {
        $this->assertSame(OAuthFailureReason::TOKEN_EXCHANGE_FAILED, OAuthFailureReason::fromTokenResponse(['error' => 'server_error']));
        $this->assertSame(OAuthFailureReason::TOKEN_EXCHANGE_FAILED, OAuthFailureReason::fromTokenResponse('<html>'));
        $this->assertSame(OAuthFailureReason::TOKEN_EXCHANGE_FAILED, OAuthFailureReason::fromTokenResponse(['error' => ['nested']]));
    }
}
