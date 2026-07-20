<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitApps\SMTP\HTTP\Services\MailConfigService;

/**
 * Security guard exercised through the LIVE provider registry: a client that smuggles a secret-named
 * key (SendGrid's `api_key`, secret===true in its fields()) under `settings` must have it stripped
 * before persistence, so a secret never lands in the options table as plaintext. Covers the real
 * secretKeysFromRegistry() path (array_filter secret + array_column key) the unit tier can only stub.
 *
 * @internal
 *
 * @coversNothing
 */
final class SettingsSecretCollisionStripTest extends IntegrationTestCase
{
    public function testSmuggledSecretKeyStrippedFromStoredSettingsViaRealRegistry(): void
    {
        (new MailConfigService())->saveConnection([
            'id'           => '',
            'provider'     => 'sendgrid',
            'kind'         => 'api',
            'name'         => 'SendGrid',
            'enabled'      => true,
            'fromEmail'    => 'from@example.com',
            'fromName'     => 'From',
            'replyToEmail' => '',
            'settings'     => ['api_key' => 'SG.smuggled', 'sandbox' => 'on'],
            'credentials'  => ['api_key' => ['source' => 'database', 'value' => 'SG.legit']],
        ]);

        $stored   = Config::getOption('options');
        $settings = $stored['connections'][0]['settings'];

        $this->assertArrayNotHasKey('api_key', $settings, 'Secret-named settings key must be stripped by the real registry path');
        $this->assertSame('on', $settings['sandbox'], 'Non-secret settings must pass through untouched');
        $this->assertArrayHasKey('api_key', $stored['connections'][0]['credentials'], 'The legit credential must still persist');
    }
}
