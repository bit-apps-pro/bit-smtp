<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Credentials\CredentialCipher;

/**
 * Covers Cloudflare's full save/read/edit credential lifecycle. The test catches a provider
 * sanitizer omission, a missing encrypt/decrypt walk, or a masked-edit resolver regression.
 *
 * @internal
 *
 * @coversNothing
 */
final class CloudflareConnectionPersistenceTest extends IntegrationTestCase
{
    private const ACCOUNT_ID = '0123456789abcdef0123456789abcdef';

    public function testSavedCloudflareTokenDecryptsInternallyStaysMaskedForApiAndSurvivesMaskedResave(): void
    {
        $token   = 'cloudflare-api-token';
        $service = new MailConfigService();

        $service->saveConnection([
            'id'           => '',
            'provider'     => 'cloudflare',
            'kind'         => 'api',
            'name'         => 'Cloudflare',
            'enabled'      => true,
            'fromEmail'    => 'from@example.org',
            'fromName'     => 'From',
            'replyToEmail' => '',
            'settings'     => ['account_id' => self::ACCOUNT_ID],
            'credentials'  => ['api_token' => ['source' => 'database', 'value' => $token]],
        ]);

        $raw = Config::getOption('options');
        $this->assertStringStartsWith(
            CredentialCipher::VERSION_PREFIX,
            $raw['connections'][0]['credentials']['api_token']['value'],
            'Cloudflare tokens must be encrypted at rest.'
        );

        $stored = (new MailConfigService())->load()->getConnections()->first();
        $this->assertNotNull($stored);
        $this->assertSame('cloudflare', $stored->getProvider());
        $this->assertSame(self::ACCOUNT_ID, $stored->setting('account_id'));
        $this->assertSame($token, $stored->getCredentials()['api_token']['value']);

        $api = (new MailConfigService())->apiSettings();
        $this->assertSame('********', $api['connections'][0]['credentials']['api_token']['value']);

        $api['connections'][0]['name'] = 'Updated Cloudflare';
        $this->assertTrue((new MailConfigService())->saveSettings($api));

        $resaved = (new MailConfigService())->load()->getConnections()->first();
        $this->assertNotNull($resaved);
        $this->assertSame('Updated Cloudflare', $resaved->getName());
        $this->assertSame(
            $token,
            $resaved->getCredentials()['api_token']['value'],
            'Re-saving the API mask must restore the encrypted Cloudflare token instead of overwriting it.'
        );
    }
}
