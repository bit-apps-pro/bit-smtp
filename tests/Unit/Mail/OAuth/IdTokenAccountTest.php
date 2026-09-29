<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Mail\OAuth;

use BitApps\SMTP\Mail\OAuth\IdTokenAccount;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class IdTokenAccountTest extends BaseUnitTestCase
{
    public function testReadsTheEmailClaim(): void
    {
        $this->assertSame('owner@example.com', IdTokenAccount::email(['id_token' => self::jwt(['email' => 'owner@example.com'])]));
    }

    public function testFallsBackToPreferredUsernameWhenItIsAnEmail(): void
    {
        $token = self::jwt(['preferred_username' => 'user@contoso.onmicrosoft.com']);

        $this->assertSame('user@contoso.onmicrosoft.com', IdTokenAccount::email(['id_token' => $token]));
    }

    public function testIgnoresClaimsThatAreNotEmails(): void
    {
        $token = self::jwt(['email' => '<b>x</b>', 'preferred_username' => '+15551234567']);

        $this->assertSame('', IdTokenAccount::email(['id_token' => $token]));
    }

    public function testReturnsEmptyForMissingOrMalformedTokens(): void
    {
        $this->assertSame('', IdTokenAccount::email([]));
        $this->assertSame('', IdTokenAccount::email(['id_token' => 'not-a-jwt']));
        $this->assertSame('', IdTokenAccount::email(['id_token' => 'a.!!!.c']));
    }

    /**
     * Unsigned JWT with base64url, unpadded segments carrying the given claims.
     *
     * @param array<string,string> $claims
     */
    private static function jwt(array $claims): string
    {
        $encode = static fn (string $json): string => rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        return $encode('{"alg":"RS256"}') . '.' . $encode((string) json_encode($claims)) . '.signature';
    }
}
