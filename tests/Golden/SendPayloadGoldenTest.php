<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Golden;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Client\HttpClient;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Aws\SigV4Signer;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\ProviderInterface;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\OAuth\OAuth2TokenProvider;
use BitApps\SMTP\Mail\Providers\Brevo\BrevoProvider;
use BitApps\SMTP\Mail\Providers\Cloudflare\CloudflareProvider;
use BitApps\SMTP\Mail\Providers\Mailgun\MailgunProvider;
use BitApps\SMTP\Mail\Providers\Mailjet\MailjetProvider;
use BitApps\SMTP\Mail\Providers\Postmark\PostmarkProvider;
use BitApps\SMTP\Mail\Providers\Resend\ResendProvider;
use BitApps\SMTP\Mail\Providers\SparkPost\SparkPostProvider;
use BitApps\SMTP\Mail\Providers\Zepto\ZeptoProvider;
use BitApps\SMTP\Tests\Golden\Support\GoldenTestCase;
use BitApps\SMTP\Tests\Golden\Support\WpStubs;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

/**
 * Freezes each descriptor-based API provider's DescriptorApiTransport::buildBody() output — the
 * request body shape actually posted to the provider. Wired the same way Plugin::registerProviders()
 * builds these providers (spec §5/§11). A later PR introducing descriptor value objects must
 * reproduce these payloads byte-for-byte.
 *
 * Deliberately skipped (no shared DescriptorApiTransport::buildBody() to freeze — see
 * tests/Unit/Mail/Providers/{SendGrid,Gmail,AmazonSes,Microsoft365}/*TransportTest.php instead):
 * SendGrid, Gmail, Amazon SES, Microsoft365.
 *
 * @internal
 *
 * @coversNothing
 */
final class SendPayloadGoldenTest extends GoldenTestCase
{
    private ApiClient $apiClient;

    private AuthorizationResolver $authResolver;

    protected function setUp(): void
    {
        parent::setUp();
        WpStubs::install();

        $this->apiClient    = new ApiClient(new HttpClient());
        $tokenProvider      = new OAuth2TokenProvider($this->apiClient, new MailConfigService());
        $this->authResolver = new AuthorizationResolver($tokenProvider, new SigV4Signer());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function providerKeys(): iterable
    {
        yield 'postmark'  => ['postmark'];
        yield 'brevo'     => ['brevo'];
        yield 'cloudflare' => ['cloudflare'];
        yield 'resend'    => ['resend'];
        yield 'mailjet'   => ['mailjet'];
        yield 'zeptomail' => ['zeptomail'];
        yield 'mailgun'   => ['mailgun'];
        yield 'sparkpost' => ['sparkpost'];
    }

    // setUp() reruns per data set (see ProviderMetadataGoldenTest), so each provider's golden
    // generates independently under UPDATE_GOLDEN=1 in one run instead of stopping at the first
    // missing snapshot.
    #[DataProvider('providerKeys')]
    public function testBuildBodyGoldenPerApiProvider(string $key): void
    {
        $transport = $this->providerFor($key)->transport();

        $ref = new ReflectionMethod($transport, 'buildBody');
        $ref->setAccessible(true);
        $body = $ref->invoke($transport, $this->message(), $this->connectionFor($key));

        $this->assertMatchesGolden($body, 'buildbody_' . $key);
    }

    private function providerFor(string $key): ProviderInterface
    {
        switch ($key) {
            case 'postmark':  return new PostmarkProvider($this->apiClient, $this->authResolver);
            case 'brevo':     return new BrevoProvider($this->apiClient, $this->authResolver);
            case 'cloudflare': return new CloudflareProvider($this->apiClient, $this->authResolver);
            case 'resend':    return new ResendProvider($this->apiClient, $this->authResolver);
            case 'mailjet':   return new MailjetProvider($this->apiClient, $this->authResolver);
            case 'zeptomail': return new ZeptoProvider($this->apiClient, $this->authResolver);
            case 'mailgun':   return new MailgunProvider($this->apiClient, $this->authResolver);
            case 'sparkpost': return new SparkPostProvider($this->apiClient, $this->authResolver);
            default:
                throw new InvalidArgumentException("Unknown provider key: {$key}");
        }
    }

    /**
     * buildBody() only ever reads a connection's from/reply-to address fields (region/domain
     * settings are consumed by endpoint(), never by buildBody()) — settings below are included
     * purely for a realistic per-provider fixture, not because buildBody() requires them.
     */
    private function connectionFor(string $key): Connection
    {
        $base = [
            'id'        => 'conn_' . $key,
            'provider'  => $key,
            'kind'      => 'api',
            'fromEmail' => 'sender@example.test',
            'fromName'  => 'Example Sender',
        ];

        switch ($key) {
            case 'zeptomail':
                return Connection::fromArray($base + [
                    'settings'    => ['data_center' => 'us'],
                    'credentials' => ['api_key' => ['source' => 'database', 'value' => 'TESTKEY']],
                ]);
            case 'mailgun':
                return Connection::fromArray($base + [
                    'settings'    => ['domain' => 'mg.example.test', 'region' => 'us'],
                    'credentials' => ['api_key' => ['source' => 'database', 'value' => 'TESTKEY']],
                ]);
            case 'sparkpost':
                return Connection::fromArray($base + [
                    'settings'    => ['region' => 'us'],
                    'credentials' => ['api_key' => ['source' => 'database', 'value' => 'TESTKEY']],
                ]);
            case 'mailjet':
                return Connection::fromArray($base + [
                    'settings'    => [],
                    'credentials' => [
                        'api_key'    => ['source' => 'database', 'value' => 'TESTKEY'],
                        'secret_key' => ['source' => 'database', 'value' => 'TESTSECRET'],
                    ],
                ]);
            default: // postmark, brevo, resend
                return Connection::fromArray($base + [
                    'settings'    => [],
                    'credentials' => ['api_key' => ['source' => 'database', 'value' => 'TESTKEY']],
                ]);
        }
    }

    private function message(): MailMessage
    {
        return MailMessage::fromArray([
            'to'          => ['rcpt@example.test'],
            'subject'     => 'Subj',
            'body'        => '<p>Hi</p>',
            'contentType' => 'text/html; charset=UTF-8',
            'headers'     => [],
            'metadata'    => ['bit_tracking_id' => 'TRACK123'],
        ]);
    }
}
