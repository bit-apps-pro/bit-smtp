<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Mail\Providers;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Client\HttpClient;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Aws\SigV4Signer;
use BitApps\SMTP\Mail\Credentials\DatabaseCredentialResolver;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Message\MimeBuilder;
use BitApps\SMTP\Mail\OAuth\OAuth2TokenProvider;
use BitApps\SMTP\Mail\Providers\AmazonSes\SesProvider;
use BitApps\SMTP\Mail\Providers\AmazonSes\SesTransport;
use BitApps\SMTP\Mail\Providers\Brevo\BrevoProvider;
use BitApps\SMTP\Mail\Providers\Cloudflare\CloudflareProvider;
use BitApps\SMTP\Mail\Providers\Gmail\GmailProvider;
use BitApps\SMTP\Mail\Providers\Gmail\GmailTransport;
use BitApps\SMTP\Mail\Providers\Mailgun\MailgunProvider;
use BitApps\SMTP\Mail\Providers\Mailjet\MailjetProvider;
use BitApps\SMTP\Mail\Providers\Microsoft365\Microsoft365Provider;
use BitApps\SMTP\Mail\Providers\Microsoft365\Microsoft365Transport;
use BitApps\SMTP\Mail\Providers\OtherSmtp\OtherSmtpProvider;
use BitApps\SMTP\Mail\Providers\PhpSendmail\PhpSendmailProvider;
use BitApps\SMTP\Mail\Providers\Postmark\PostmarkProvider;
use BitApps\SMTP\Mail\Providers\ProviderGuide;
use BitApps\SMTP\Mail\Providers\ProviderRegistry;
use BitApps\SMTP\Mail\Providers\Resend\ResendProvider;
use BitApps\SMTP\Mail\Providers\SendGrid\SendGridProvider;
use BitApps\SMTP\Mail\Providers\SendGrid\SendGridTransport;
use BitApps\SMTP\Mail\Providers\SparkPost\SparkPostProvider;
use BitApps\SMTP\Mail\Providers\Zepto\ZeptoProvider;
use BitApps\SMTP\Mail\Transport\PhpSendmailTransport;
use BitApps\SMTP\Mail\Transport\SmtpTransport;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use BitApps\SMTP\Tests\Golden\Support\WpStubs;
use InvalidArgumentException;

/**
 * @internal
 *
 * @coversNothing
 */
final class ProviderGuideInvariantsTest extends BaseUnitTestCase
{
    private ProviderRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        WpStubs::install();

        $this->registry = self::productionRegistry();
    }

    public function testEveryRegisteredProviderPublishesACompleteGuide(): void
    {
        foreach ($this->registry->all() as $provider) {
            $key   = $provider->key();
            $guide = $provider->guide();

            $this->assertContains($guide['group'] ?? null, ProviderGuide::GROUPS, $key);
            $this->assertNotSame('', trim($guide['bestFor']), $key);
            $this->assertNotEmpty($guide['prerequisites'], $key);

            $ids = array_column($guide['prerequisites'], 'id');
            $this->assertSame($ids, array_unique($ids), $key);

            foreach ($guide['prerequisites'] as $step) {
                $this->assertNotSame('', trim($step['title']), $key);
                $this->assertNotSame('', trim($step['body']), $key);
            }
        }
    }

    public function testEveryDocUrlIsAnAbsoluteHttpsUrl(): void
    {
        foreach ($this->docUrls() as $label => $url) {
            $this->assertSame('https', parse_url($url, PHP_URL_SCHEME), $label);
            $this->assertNotEmpty(parse_url($url, PHP_URL_HOST), $label);
        }
    }

    public function testMetadataMergesEachProvidersOwnGuide(): void
    {
        $byKey = array_column($this->registry->metadata(), null, 'key');

        foreach ($this->registry->all() as $provider) {
            $entry = $byKey[$provider->key()];

            $this->assertSame($provider->guide(), array_intersect_key($entry, $provider->guide()), $provider->key());
        }
    }

    public function testMakeEmitsCanonicalKeyOrderAndOmitsUnsetOptionals(): void
    {
        $step  = ProviderGuide::step('only', 'Title', 'Body');
        $guide = ProviderGuide::make(
            group: ProviderGuide::GROUP_SERVER,
            bestFor: 'Best',
            prerequisites: [$step],
            testCaveat: 'Caveat',
            docUrl: 'https://example.test/'
        );

        $this->assertSame(['group', 'bestFor', 'docUrl', 'testCaveat', 'prerequisites'], array_keys($guide));
        $this->assertArrayNotHasKey('docUrl', $step);
    }

    public function testMakeRejectsAnUnknownGroup(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProviderGuide::make('newsletter', 'Best', [ProviderGuide::step('only', 'Title', 'Body')]);
    }

    public function testMakeRejectsAGuideWithoutPrerequisites(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProviderGuide::make(ProviderGuide::GROUP_SERVER, 'Best', []);
    }

    /**
     * Every docUrl across registered guides and their prerequisites, labelled by provider and step.
     *
     * @return array<string,string>
     */
    private function docUrls(): array
    {
        $urls = [];
        foreach ($this->registry->all() as $provider) {
            $key   = $provider->key();
            $guide = $provider->guide();
            if (isset($guide['docUrl'])) {
                $urls[$key] = $guide['docUrl'];
            }
            foreach ($guide['prerequisites'] as $step) {
                if (isset($step['docUrl'])) {
                    $urls[$key . '.' . $step['id']] = $step['docUrl'];
                }
            }
        }

        return $urls;
    }

    /**
     * Registry wired with the same providers MailServiceProvider registers in production.
     */
    private static function productionRegistry(): ProviderRegistry
    {
        $apiClient     = new ApiClient(new HttpClient());
        $mimeBuilder   = new MimeBuilder();
        $tokenProvider = new OAuth2TokenProvider($apiClient, new MailConfigService());
        $sigV4Signer   = new SigV4Signer();
        $authResolver  = new AuthorizationResolver($tokenProvider, $sigV4Signer);

        $registry = new ProviderRegistry();
        $registry->register(new OtherSmtpProvider(new SmtpTransport(new DatabaseCredentialResolver())));
        $registry->register(new PhpSendmailProvider(new PhpSendmailTransport()));
        $registry->register(new SendGridProvider(new SendGridTransport($apiClient)));
        $registry->register(new GmailProvider(new GmailTransport($apiClient, $tokenProvider, $mimeBuilder)));
        $registry->register(new SesProvider(new SesTransport($apiClient, $sigV4Signer, $mimeBuilder)));
        $registry->register(new PostmarkProvider($apiClient, $authResolver));
        $registry->register(new BrevoProvider($apiClient, $authResolver));
        $registry->register(new CloudflareProvider($apiClient, $authResolver));
        $registry->register(new ResendProvider($apiClient, $authResolver));
        $registry->register(new MailjetProvider($apiClient, $authResolver));
        $registry->register(new ZeptoProvider($apiClient, $authResolver));
        $registry->register(new MailgunProvider($apiClient, $authResolver));
        $registry->register(new SparkPostProvider($apiClient, $authResolver));
        $registry->register(new Microsoft365Provider(new Microsoft365Transport($apiClient, $tokenProvider, $mimeBuilder)));

        return $registry;
    }
}
