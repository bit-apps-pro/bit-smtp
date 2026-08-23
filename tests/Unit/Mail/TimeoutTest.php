<?php

namespace BitApps\SMTP\Tests\Unit\Mail;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Client\HttpClient;
use BitApps\SMTP\Mail\Contracts\CredentialResolverInterface;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Transport\SmtpTransport;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;
use ReflectionProperty;

/**
 * Proves the `send_timeout_seconds` preference reaches both send paths: SmtpTransport's injected
 * PHPMailer timeout, and ApiClient's per-request timeout option. Real PHPMailer is unavailable in
 * this tier (see MimeBuilderTest), so PHPMailer-touching assertions live in the integration suite
 * (tests/Integration/SmtpTransportTest.php); here the transport's stored config and ApiClient's
 * actual request-building are exercised directly.
 *
 * @internal
 *
 * @coversNothing
 */
final class TimeoutTest extends BaseUnitTestCase
{
    public function testSmtpTransportStoresAnInjectedTimeoutSeconds(): void
    {
        $transport = new SmtpTransport(Mockery::mock(CredentialResolverInterface::class), 15);

        self::assertSame(15, $this->timeoutSecondsOf($transport));
    }

    public function testSmtpTransportDefaultsTimeoutSecondsToThirtyWhenNotProvided(): void
    {
        $transport = new SmtpTransport(Mockery::mock(CredentialResolverInterface::class));

        self::assertSame(30, $this->timeoutSecondsOf($transport));
    }

    public function testApiClientAppliesAnExplicitTimeoutToTheOutboundRequestOptions(): void
    {
        Functions\when('wp_parse_args')->alias(static function ($args, $defaults = []) {
            return array_merge($defaults, (array) $args);
        });
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_body')->justReturn('');
        Functions\when('wp_remote_retrieve_headers')->justReturn([]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);

        Functions\expect('wp_safe_remote_request')
            ->once()
            ->andReturnUsing(static function ($url, $options) {
                self::assertSame(15, $options['timeout']);

                return 'response-fixture';
            });

        $client = (new ApiClient(new HttpClient()))->withTimeout(15);
        $client->post('https://api.example.com/send', ['to' => 'a@example.com']);
    }

    public function testApiClientWithoutAnExplicitTimeoutKeepsHttpClientsThirtySecondDefault(): void
    {
        Functions\when('wp_parse_args')->alias(static function ($args, $defaults = []) {
            return array_merge($defaults, (array) $args);
        });
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_body')->justReturn('');
        Functions\when('wp_remote_retrieve_headers')->justReturn([]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);

        Functions\expect('wp_safe_remote_request')
            ->once()
            ->andReturnUsing(static function ($url, $options) {
                // send_timeout_seconds' schema default (30) matches HttpClient's own built-in
                // default, so an ApiClient the wiring never applied withTimeout() to still behaves
                // as if the pref default were in effect.
                self::assertSame(30, $options['timeout']);

                return 'response-fixture';
            });

        $client = new ApiClient(new HttpClient());
        $client->post('https://api.example.com/send', ['to' => 'a@example.com']);
    }

    private function timeoutSecondsOf(SmtpTransport $transport): int
    {
        return (new ReflectionProperty(SmtpTransport::class, 'timeoutSeconds'))->getValue($transport);
    }
}
