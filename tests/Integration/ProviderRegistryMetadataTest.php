<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Plugin;

/**
 * Verifies the live plugin registers all fourteen mail providers, so `GET mail/providers` (backed by
 * ProviderRegistry::metadata()) drives the frontend provider modal + metadata-driven fields.
 *
 * @internal
 *
 * @coversNothing
 */
final class ProviderRegistryMetadataTest extends IntegrationTestCase
{
    public function testMetadataExposesAllFourteenRegisteredProviders(): void
    {
        $metadata = Plugin::instance()->providerRegistry()->metadata();

        $byKey = [];
        foreach ($metadata as $provider) {
            $byKey[$provider['key']] = $provider;
        }

        $this->assertSame(
            ['other_smtp', 'php_sendmail', 'sendgrid', 'gmail', 'amazon_ses', 'postmark', 'brevo', 'cloudflare', 'resend', 'mailjet', 'zeptomail', 'mailgun', 'sparkpost', 'microsoft365'],
            array_keys($byKey),
            'all fourteen providers register in priority-agnostic insertion order'
        );

        foreach (['other_smtp', 'php_sendmail', 'sendgrid', 'gmail', 'amazon_ses', 'postmark', 'brevo', 'cloudflare', 'resend', 'mailjet', 'zeptomail', 'mailgun', 'sparkpost', 'microsoft365'] as $key) {
            $this->assertArrayHasKey('label', $byKey[$key]);
            $this->assertArrayHasKey('kind', $byKey[$key]);
            $this->assertIsArray($byKey[$key]['fields']);
        }

        $this->assertSame('smtp', $byKey['other_smtp']['kind']);
        $this->assertSame('local', $byKey['php_sendmail']['kind']);
        $this->assertSame([], $byKey['php_sendmail']['fields']);
        $this->assertSame('api', $byKey['sendgrid']['kind']);
        $this->assertSame('api', $byKey['gmail']['kind']);
        $this->assertSame('api', $byKey['amazon_ses']['kind']);
        $this->assertSame('api', $byKey['postmark']['kind']);
        $this->assertSame('api', $byKey['brevo']['kind']);
        $this->assertSame('api', $byKey['cloudflare']['kind']);
        $this->assertSame('api', $byKey['resend']['kind']);
        $this->assertSame('api', $byKey['mailjet']['kind']);
        $this->assertSame('api', $byKey['zeptomail']['kind']);
        $this->assertSame('api', $byKey['mailgun']['kind']);
        $this->assertSame('api', $byKey['sparkpost']['kind']);
        $this->assertSame('api', $byKey['microsoft365']['kind']);

        $oauthRedirectUrl = 'http://example.org/bit-smtp/oauth/callback';
        foreach (['gmail', 'microsoft365'] as $key) {
            $this->assertSame($oauthRedirectUrl, $byKey[$key]['oauth_redirect_url'], "{$key} must expose the exact OAuth callback URL");
        }
        foreach (['other_smtp', 'php_sendmail', 'sendgrid', 'amazon_ses', 'postmark', 'brevo', 'cloudflare', 'resend', 'mailjet', 'zeptomail', 'mailgun', 'sparkpost'] as $key) {
            $this->assertArrayNotHasKey('oauth_redirect_url', $byKey[$key], "{$key} must not expose an OAuth callback URL");
        }

        foreach (['sendgrid', 'postmark', 'brevo', 'resend', 'mailjet', 'zeptomail', 'mailgun', 'sparkpost'] as $key) {
            $this->assertTrue($byKey[$key]['supports_webhook'], "{$key} must expose its live webhook receiver");
        }
        foreach (['other_smtp', 'php_sendmail', 'gmail', 'amazon_ses', 'cloudflare', 'microsoft365'] as $key) {
            $this->assertFalse($byKey[$key]['supports_webhook'], "{$key} must not advertise an unimplemented webhook");
        }

        // Providers with an API to register their own webhook advertise provisioning; ZeptoMail has no
        // such API and the non-webhook providers have no receiver, so both stay manual/absent.
        foreach (['sendgrid', 'brevo', 'postmark', 'sparkpost', 'mailgun', 'mailjet', 'resend'] as $key) {
            $this->assertTrue($byKey[$key]['supports_webhook_provisioning'], "{$key} provisions its own webhook via API");
        }
        foreach (['other_smtp', 'php_sendmail', 'gmail', 'amazon_ses', 'cloudflare', 'zeptomail', 'microsoft365'] as $key) {
            $this->assertFalse($byKey[$key]['supports_webhook_provisioning'], "{$key} must not advertise API webhook provisioning");
        }

        $postmarkFieldKeys = array_column($byKey['postmark']['fields'], 'key');
        $this->assertContains('api_key', $postmarkFieldKeys, 'postmark must expose an api_key field');

        $brevoFieldKeys = array_column($byKey['brevo']['fields'], 'key');
        $this->assertContains('api_key', $brevoFieldKeys, 'brevo must expose an api_key field');

        $cloudflareFieldKeys = array_column($byKey['cloudflare']['fields'], 'key');
        $this->assertContains('account_id', $cloudflareFieldKeys, 'cloudflare must expose an account_id field');
        $this->assertContains('api_token', $cloudflareFieldKeys, 'cloudflare must expose an api_token field');

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

        $sparkpostFieldKeys = array_column($byKey['sparkpost']['fields'], 'key');
        $this->assertContains('api_key', $sparkpostFieldKeys, 'sparkpost must expose an api_key field');
        $this->assertContains('region', $sparkpostFieldKeys, 'sparkpost must expose a region field');

        $microsoft365FieldKeys = array_column($byKey['microsoft365']['fields'], 'key');
        $this->assertContains('client_id', $microsoft365FieldKeys, 'microsoft365 must expose a client_id field');
        $this->assertContains('client_secret', $microsoft365FieldKeys, 'microsoft365 must expose a client_secret field');
        $this->assertContains('tenant', $microsoft365FieldKeys, 'microsoft365 must expose a tenant field');
        $this->assertContains('oauth', $microsoft365FieldKeys, 'microsoft365 must expose an oauth marker field');
    }
}
