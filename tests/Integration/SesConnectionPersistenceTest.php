<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\HTTP\Services\MailConfigService;

/**
 * Regression guard for the settings sanitizer wiping provider-specific settings: a stored Amazon
 * SES connection must keep its non-secret settings (access_key, region) and its secret (secret_key)
 * across a saveConnection round-trip — the exact persistence path the sanitizer allow-list broke.
 *
 * @internal
 *
 * @coversNothing
 */
final class SesConnectionPersistenceTest extends IntegrationTestCase
{
    public function testSesSettingsAndSecretSurviveSaveConnectionRoundTrip(): void
    {
        (new MailConfigService())->saveConnection([
            'id'           => '',
            'provider'     => 'amazon_ses',
            'kind'         => 'api',
            'name'         => 'SES',
            'enabled'      => true,
            'fromEmail'    => 'from@example.com',
            'fromName'     => 'From',
            'replyToEmail' => '',
            'settings'     => ['access_key' => 'AKIAEXAMPLE', 'region' => 'eu-central-1'],
            'credentials'  => ['secret_key' => ['source' => 'database', 'value' => 'ses-secret-value']],
        ]);

        // A cold service (production rebuilds it per request) reloads the persisted connection.
        $connection = (new MailConfigService())->load()->getConnections()->first();

        $this->assertNotNull($connection);
        $this->assertSame('amazon_ses', $connection->getProvider());
        $this->assertSame('AKIAEXAMPLE', $connection->setting('access_key'));
        $this->assertSame('eu-central-1', $connection->setting('region'));
        $this->assertSame('ses-secret-value', $connection->getCredentials()['secret_key']['value']);
    }
}
