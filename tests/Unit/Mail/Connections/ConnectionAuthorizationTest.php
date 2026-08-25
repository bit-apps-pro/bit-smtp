<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Mail\Connections;

use BitApps\SMTP\Mail\Connections\ConnectionAuthorization;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class ConnectionAuthorizationTest extends BaseUnitTestCase
{
    public function testNonOAuthConnectionIsAlwaysSendable(): void
    {
        $this->assertTrue(ConnectionAuthorization::isSendable(['provider' => 'other_smtp'], false));
    }

    public function testOAuthConnectionWithRefreshTokenIsSendable(): void
    {
        $connection = ['credentials' => ['refresh_token' => ['source' => 'database', 'value' => 'rt']]];

        $this->assertTrue(ConnectionAuthorization::isSendable($connection, true));
    }

    public function testOAuthConnectionWithOnlyAccessTokenIsSendable(): void
    {
        $connection = ['credentials' => ['access_token' => ['source' => 'database', 'value' => 'at']]];

        $this->assertTrue(ConnectionAuthorization::isSendable($connection, true));
    }

    public function testOAuthConnectionWithoutAnyTokenIsNotSendable(): void
    {
        $connection = ['credentials' => ['client_secret' => ['source' => 'database', 'value' => 'cs']]];

        $this->assertFalse(ConnectionAuthorization::isSendable($connection, true));
    }

    public function testOAuthConnectionWithBlankTokenIsNotSendable(): void
    {
        $connection = ['credentials' => ['refresh_token' => ['source' => 'database', 'value' => '   ']]];

        $this->assertFalse(ConnectionAuthorization::isSendable($connection, true));
    }

    public function testOAuthConnectionWithNoCredentialsKeyIsNotSendable(): void
    {
        $this->assertFalse(ConnectionAuthorization::isSendable(['provider' => 'gmail'], true));
    }
}
