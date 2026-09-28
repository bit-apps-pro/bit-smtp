<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitApps\SMTP\HTTP\Services\MailConfigService;

/**
 * Verifies smuggled secret-named keys stripped from settings before persistence via real registry.
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
