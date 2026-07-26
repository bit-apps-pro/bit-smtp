<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Golden;

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
use BitApps\SMTP\Mail\Providers\Gmail\GmailProvider;
use BitApps\SMTP\Mail\Providers\Gmail\GmailTransport;
use BitApps\SMTP\Mail\Providers\Mailgun\MailgunProvider;
use BitApps\SMTP\Mail\Providers\Mailjet\MailjetProvider;
use BitApps\SMTP\Mail\Providers\Microsoft365\Microsoft365Provider;
use BitApps\SMTP\Mail\Providers\Microsoft365\Microsoft365Transport;
use BitApps\SMTP\Mail\Providers\OtherSmtp\OtherSmtpProvider;
use BitApps\SMTP\Mail\Providers\PhpSendmail\PhpSendmailProvider;
use BitApps\SMTP\Mail\Providers\Postmark\PostmarkProvider;
use BitApps\SMTP\Mail\Providers\ProviderRegistry;
use BitApps\SMTP\Mail\Providers\Resend\ResendProvider;
use BitApps\SMTP\Mail\Providers\SendGrid\SendGridProvider;
use BitApps\SMTP\Mail\Providers\SendGrid\SendGridTransport;
use BitApps\SMTP\Mail\Providers\SparkPost\SparkPostProvider;
use BitApps\SMTP\Mail\Providers\Zepto\ZeptoProvider;
use BitApps\SMTP\Mail\Transport\PhpSendmailTransport;
use BitApps\SMTP\Mail\Transport\SmtpTransport;
use BitApps\SMTP\Tests\Golden\Support\GoldenTestCase;
use BitApps\SMTP\Tests\Golden\Support\WpStubs;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Freezes the REST `mail/providers` field-definition contract: ProviderRegistry::metadata() and
 * every provider's fields(), wired exactly as Plugin::registerProviders() does. A later PR
 * introducing FieldDefinition/ProviderMetadata value objects must reproduce these byte-for-byte.
 *
 * @internal
 *
 * @coversNothing
 */
final class ProviderMetadataGoldenTest extends GoldenTestCase
{
    private ProviderRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        WpStubs::install();

        $apiClient     = new ApiClient(new HttpClient());
        $mimeBuilder   = new MimeBuilder();
        $tokenProvider = new OAuth2TokenProvider($apiClient, new MailConfigService());
        $sigV4Signer   = new SigV4Signer();
        $authResolver  = new AuthorizationResolver($tokenProvider, $sigV4Signer);

        $this->registry = new ProviderRegistry();
        $this->registry->register(new OtherSmtpProvider(new SmtpTransport(new DatabaseCredentialResolver())));
        $this->registry->register(new PhpSendmailProvider(new PhpSendmailTransport()));
        $this->registry->register(new SendGridProvider(new SendGridTransport($apiClient)));
        $this->registry->register(new GmailProvider(new GmailTransport($apiClient, $tokenProvider, $mimeBuilder)));
        $this->registry->register(new SesProvider(new SesTransport($apiClient, $sigV4Signer, $mimeBuilder)));
        $this->registry->register(new PostmarkProvider($apiClient, $authResolver));
        $this->registry->register(new BrevoProvider($apiClient, $authResolver));
        $this->registry->register(new ResendProvider($apiClient, $authResolver));
        $this->registry->register(new MailjetProvider($apiClient, $authResolver));
        $this->registry->register(new ZeptoProvider($apiClient, $authResolver));
        $this->registry->register(new MailgunProvider($apiClient, $authResolver));
        $this->registry->register(new SparkPostProvider($apiClient, $authResolver));
        $this->registry->register(new Microsoft365Provider(new Microsoft365Transport($apiClient, $tokenProvider, $mimeBuilder)));
    }

    public function testProviderRegistryMetadataGolden(): void
    {
        $this->assertMatchesGolden($this->registry->metadata(), 'provider_metadata');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function providerKeys(): iterable
    {
        yield 'other_smtp'   => ['other_smtp'];
        yield 'php_sendmail' => ['php_sendmail'];
        yield 'sendgrid'     => ['sendgrid'];
        yield 'gmail'        => ['gmail'];
        yield 'amazon_ses'   => ['amazon_ses'];
        yield 'postmark'     => ['postmark'];
        yield 'brevo'        => ['brevo'];
        yield 'resend'       => ['resend'];
        yield 'mailjet'      => ['mailjet'];
        yield 'zeptomail'    => ['zeptomail'];
        yield 'mailgun'      => ['mailgun'];
        yield 'sparkpost'    => ['sparkpost'];
        yield 'microsoft365' => ['microsoft365'];
    }

    // setUp() reruns per data set, so each provider's golden generates independently under
    // UPDATE_GOLDEN=1 in a single test run instead of stopping at the first missing snapshot.
    #[DataProvider('providerKeys')]
    public function testFieldsGolden(string $key): void
    {
        $provider = $this->registry->get($key);
        $this->assertMatchesGolden($provider->fields(), 'fields_' . $provider->key());
    }
}
