<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Plugin;

/**
 * Verifies the live plugin registers all nine mail providers, so `GET mail/providers` (backed by
 * ProviderRegistry::metadata()) drives the frontend provider modal + metadata-driven fields.
 *
 * @internal
 *
 * @coversNothing
 */
final class ProviderRegistryMetadataTest extends IntegrationTestCase
{
    public function testMetadataExposesAllNineRegisteredProviders(): void
    {
        $metadata = Plugin::instance()->providerRegistry()->metadata();

        $byKey = [];
        foreach ($metadata as $provider) {
            $byKey[$provider['key']] = $provider;
        }

        $this->assertSame(
            ['other_smtp', 'sendgrid', 'gmail', 'amazon_ses', 'postmark', 'brevo', 'resend', 'mailjet', 'zeptomail'],
            array_keys($byKey),
            'all nine providers register in priority-agnostic insertion order'
        );

        foreach (['other_smtp', 'sendgrid', 'gmail', 'amazon_ses', 'postmark', 'brevo', 'resend', 'mailjet', 'zeptomail'] as $key) {
            $this->assertArrayHasKey('label', $byKey[$key]);
            $this->assertArrayHasKey('kind', $byKey[$key]);
            $this->assertNotEmpty($byKey[$key]['fields'], "{$key} must expose field metadata");
        }

        $this->assertSame('smtp', $byKey['other_smtp']['kind']);
        $this->assertSame('api', $byKey['sendgrid']['kind']);
        $this->assertSame('api', $byKey['gmail']['kind']);
        $this->assertSame('api', $byKey['amazon_ses']['kind']);
        $this->assertSame('api', $byKey['postmark']['kind']);
        $this->assertSame('api', $byKey['brevo']['kind']);
        $this->assertSame('api', $byKey['resend']['kind']);
        $this->assertSame('api', $byKey['mailjet']['kind']);
        $this->assertSame('api', $byKey['zeptomail']['kind']);

        $resendFieldKeys = array_column($byKey['resend']['fields'], 'key');
        $this->assertContains('api_key', $resendFieldKeys, 'resend must expose an api_key field');

        $mailjetFieldKeys = array_column($byKey['mailjet']['fields'], 'key');
        $this->assertContains('api_key', $mailjetFieldKeys, 'mailjet must expose an api_key field');
        $this->assertContains('secret_key', $mailjetFieldKeys, 'mailjet must expose a secret_key field');

        $zeptoFieldKeys = array_column($byKey['zeptomail']['fields'], 'key');
        $this->assertContains('api_key', $zeptoFieldKeys, 'zeptomail must expose an api_key field');
        $this->assertContains('data_center', $zeptoFieldKeys, 'zeptomail must expose a data_center field');
    }
}
