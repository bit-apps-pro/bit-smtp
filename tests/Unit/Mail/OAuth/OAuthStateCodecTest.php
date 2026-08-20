<?php

namespace BitApps\SMTP\Tests\Unit\Mail\OAuth;

use BitApps\SMTP\Mail\Exceptions\OAuthStateException;
use BitApps\SMTP\Mail\OAuth\OAuthStateCodec;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;

/**
 * @internal
 *
 * @coversNothing
 */
class OAuthStateCodecTest extends BaseUnitTestCase
{
    private const SECRET = 'unit-test-secret-salt';

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_salt')->justReturn(self::SECRET);
    }

    public function testEncodeDecodeRoundTripsConnectionIdAndProvider(): void
    {
        $decoded = OAuthStateCodec::decode(OAuthStateCodec::encode('conn_123', 'gmail'));

        $this->assertSame('conn_123', $decoded['connection_id']);
        $this->assertSame('gmail', $decoded['provider']);
    }

    public function testTamperedSignatureIsRejected(): void
    {
        [$payload, $signature] = explode('.', OAuthStateCodec::encode('conn_123', 'gmail'));
        $signature[0]          = $signature[0] === 'a' ? 'b' : 'a';

        $this->expectException(OAuthStateException::class);
        OAuthStateCodec::decode($payload . '.' . $signature);
    }

    public function testTamperedPayloadWithStaleSignatureIsRejected(): void
    {
        [, $signature] = explode('.', OAuthStateCodec::encode('conn_123', 'gmail'));

        $forged = rtrim(strtr(base64_encode((string) json_encode([
            'connection_id' => 'attacker_connection',
            'provider'      => 'gmail',
            'iat'           => time(),
        ])), '+/', '-_'), '=');

        $this->expectException(OAuthStateException::class);
        OAuthStateCodec::decode($forged . '.' . $signature);
    }

    public function testExpiredStateWithValidSignatureIsRejected(): void
    {
        $encoded = rtrim(strtr(base64_encode((string) json_encode([
            'connection_id' => 'conn_1',
            'provider'      => 'gmail',
            'iat'           => time() - 3600,
        ])), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $encoded, self::SECRET);

        $this->expectException(OAuthStateException::class);
        OAuthStateCodec::decode($encoded . '.' . $signature);
    }

    public function testGarbageStateIsRejected(): void
    {
        $this->expectException(OAuthStateException::class);
        OAuthStateCodec::decode('not-a-valid-state');
    }
}
