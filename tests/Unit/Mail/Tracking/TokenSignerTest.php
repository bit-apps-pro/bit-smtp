<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Tracking;

use BitApps\SMTP\Mail\Tracking\TokenSigner;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;

/**
 * @internal
 *
 * @coversNothing
 */
final class TokenSignerTest extends BaseUnitTestCase
{
    private const SECRET = 'unit-test-tracking-salt';

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_salt')->justReturn(self::SECRET);
    }

    public function testSignVerifyRoundTripsThePayload(): void
    {
        $payload = ['t' => 'a1b2-uuid', 'u' => 'https://example.test/order/42?ref=1&x=2'];

        $decoded = TokenSigner::verify(TokenSigner::sign($payload));

        $this->assertSame($payload, $decoded);
    }

    public function testTokenOutputIsUrlSafe(): void
    {
        $token = TokenSigner::sign(['u' => 'https://example.test/a+b/c?d=1&e=2']);

        // base64url payload + '.' + hex hmac: never the URL-reserved '+', '/', or '=' characters.
        $this->assertMatchesRegularExpression('#^[A-Za-z0-9\-_]+\.[a-f0-9]{64}$#', $token);
        $this->assertStringNotContainsString('+', $token);
        $this->assertStringNotContainsString('/', $token);
        $this->assertStringNotContainsString('=', $token);
    }

    public function testTamperedSignatureIsRejected(): void
    {
        [$payload, $signature] = explode('.', TokenSigner::sign(['t' => 'x']));
        $signature[0]          = $signature[0] === 'a' ? 'b' : 'a';

        $this->assertNull(TokenSigner::verify($payload . '.' . $signature));
    }

    public function testTamperedPayloadWithStaleSignatureIsRejected(): void
    {
        [, $signature] = explode('.', TokenSigner::sign(['t' => 'original']));

        $forged = rtrim(strtr(base64_encode((string) json_encode(['t' => 'attacker'])), '+/', '-_'), '=');

        $this->assertNull(TokenSigner::verify($forged . '.' . $signature));
    }

    public function testTokenSignedWithADifferentSecretIsRejected(): void
    {
        $token = TokenSigner::sign(['t' => 'x']);

        // A token minted under a different WP salt (e.g. another site) must not verify here.
        Functions\when('wp_salt')->justReturn('a-different-server-secret');

        $this->assertNull(TokenSigner::verify($token));
    }

    public function testGarbageAndMalformedTokensAreRejected(): void
    {
        $this->assertNull(TokenSigner::verify('not-a-valid-token'));
        $this->assertNull(TokenSigner::verify(''));
        $this->assertNull(TokenSigner::verify('.'));
        $this->assertNull(TokenSigner::verify('onlyonepart'));
        $this->assertNull(TokenSigner::verify('a.b.c'));
    }
}
