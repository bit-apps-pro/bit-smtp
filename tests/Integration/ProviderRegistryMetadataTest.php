<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Plugin;

/**
 * Verifies the live plugin registers all ten mail providers, so `GET mail/providers` (backed by
 * ProviderRegistry::metadata()) drives the frontend provider modal + metadata-driven fields.
 *
 * @internal
 *
 * @coversNothing
 */
final class ProviderRegistryMetadataTest extends IntegrationTestCase
{
    public function testMetadataExposesAllTenRegisteredProviders(): void
    {
        $metadata = Plugin::instance()->providerRegistry()->metadata();

        $byKey = [];
        foreach ($metadata as $provider) {
            $byKey[$provider['key']] = $provider;
        }

        $this->assertSame(
            ['other_smtp', 'sendgrid', 'gmail', 'amazon_ses', 'postmark', 'brevo', 'resend', 'mailjet', 'zeptomail', 'mailgun'],
            array_keys($byKey),
            'all ten providers register in priority-agnostic insertion order'
        );

        foreach (['other_smtp', 'sendgrid', 'gmail', 'amazon_ses', 'postmark', 'brevo', 'resend', 'mailjet', 'zeptomail', 'mailgun'] as $key) {
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
        $this->assertSame('api', $byKey['mailgun']['kind']);

        $postmarkFieldKeys = array_column($byKey['postmark']['fields'], 'key');
        $this->assertContains('api_key', $postmarkFieldKeys, 'postmark must expose an api_key field');

        $brevoFieldKeys = array_column($byKey['brevo']['fields'], 'key');
        $this->assertContains('api_key', $brevoFieldKeys, 'brevo must expose an api_key field');

        $resendFieldKeys = array_column($byKey['resend']['fields'], 'key');
        $this->assertContains('api_key', $resendFieldKeys, 'resend must expose an api_key field');

        $mailjetFieldKeys = array_column($byKey['mailjet']['fields'], 'key');
        $this->assertContains('api_key', $mailjetFieldKeys, 'mailjet must expose an api_key field');
        $this->assertContains('secret_key', $mailjetFieldKeys, 'mailjet must expose a secret_key field');

        $zeptoFieldKeys = array_column($byKey['zeptomail']['fields'], 'key');
        $this->assertContains('api_key', $zeptoFieldKeys, 'zeptomail must expose an api_key field');
        $this->assertContains('data_center', $zeptoFieldKeys, 'zeptomail must expose a data_center field');

        $mailgunFieldKeys = array_column($byKey['mailgun']['fields'], 'key');
        $this->assertContains('api_key', $mailgunFieldKeys, 'mailgun must expose an api_key field');
        $this->assertContains('domain', $mailgunFieldKeys, 'mailgun must expose a domain field');
        $this->assertContains('region', $mailgunFieldKeys, 'mailgun must expose a region field');
    }
}
