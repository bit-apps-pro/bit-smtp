<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\HTTP\Services\MailConfigService;

/**
 * Regression guard: SES connection persists settings and secrets across save/reload round-trips.
 *
 * @internal
 *
 * @coversNothing
 */
final class SesConnectionPersistenceTest extends IntegrationTestCase
{
    /**
     * Save and reload connection to verify settings/secret persistence (cold service reloads per request).
     */
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

        $connection = (new MailConfigService())->load()->getConnections()->first();

        $this->assertNotNull($connection);
        $this->assertSame('amazon_ses', $connection->getProvider());
        $this->assertSame('AKIAEXAMPLE', $connection->setting('access_key'));
        $this->assertSame('eu-central-1', $connection->setting('region'));
        $this->assertSame('ses-secret-value', $connection->getCredentials()['secret_key']['value']);
    }
}
