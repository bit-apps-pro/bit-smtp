<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Providers;

use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Aws\SigV4Signer;
use BitApps\SMTP\Mail\Contracts\ProviderInterface;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\OAuth\OAuth2TokenProvider;
use BitApps\SMTP\Mail\Providers\Brevo\BrevoProvider;
use BitApps\SMTP\Mail\Providers\Cloudflare\CloudflareProvider;
use BitApps\SMTP\Mail\Providers\Mailgun\MailgunProvider;
use BitApps\SMTP\Mail\Providers\Mailjet\MailjetProvider;
use BitApps\SMTP\Mail\Providers\Postmark\PostmarkProvider;
use BitApps\SMTP\Mail\Providers\Resend\ResendProvider;
use BitApps\SMTP\Mail\Providers\SparkPost\SparkPostProvider;
use BitApps\SMTP\Mail\Providers\Zepto\ZeptoProvider;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Pins the editor-facing field layout of descriptor providers: region first, webhook secrets in the tracking section, help on every field.
 *
 * @internal
 *
 * @coversNothing
 */
final class ProviderFieldLayoutTest extends BaseUnitTestCase
{
    /**
     * Provider class and its expected field keys, in render order.
     *
     * @return array<string, array{class-string<ProviderInterface>, list<string>}>
     */
    public static function providerLayouts(): array
    {
        return [
            'mailgun'    => [MailgunProvider::class, ['region', 'domain', 'api_key', 'webhook_signing_key']],
            'sparkpost'  => [SparkPostProvider::class, ['region', 'api_key']],
            'zeptomail'  => [ZeptoProvider::class, ['data_center', 'api_key', 'webhook_auth_key']],
            'resend'     => [ResendProvider::class, ['api_key', 'webhook_signing_secret']],
            'brevo'      => [BrevoProvider::class, ['api_key']],
            'postmark'   => [PostmarkProvider::class, ['api_key']],
            'mailjet'    => [MailjetProvider::class, ['api_key', 'secret_key']],
            'cloudflare' => [CloudflareProvider::class, ['account_id', 'api_token']],
        ];
    }

    /**
     * Provider classes alone, for per-field assertions.
     *
     * @return array<string, array{class-string<ProviderInterface>}>
     */
    public static function providerClasses(): array
    {
        return array_map(static fn (array $layout): array => [$layout[0]], self::providerLayouts());
    }

    /**
     * Fields render in the declared order.
     *
     * @param class-string<ProviderInterface> $class
     * @param list<string>                    $expectedKeys
     */
    #[DataProvider('providerLayouts')]
    public function testFieldsRenderInTheExpectedOrder(string $class, array $expectedKeys): void
    {
        $this->assertSame($expectedKeys, array_column($this->make($class)->fields(), 'key'));
    }

    /**
     * Every field carries a non-empty visible hint.
     *
     * @param class-string<ProviderInterface> $class
     */
    #[DataProvider('providerClasses')]
    public function testEveryFieldHasHelpText(string $class): void
    {
        foreach ($this->make($class)->fields() as $field) {
            $this->assertNotSame('', $field['help']['text'] ?? '', "Field '{$field['key']}' needs help text");
        }
    }

    /**
     * Only webhook signing secrets live in the tracking section, and they stay optional secrets.
     *
     * @param class-string<ProviderInterface> $class
     */
    #[DataProvider('providerClasses')]
    public function testOnlyWebhookSecretsAreInTheTrackingSection(string $class): void
    {
        foreach ($this->make($class)->fields() as $field) {
            $isWebhookSecret = str_starts_with($field['key'], 'webhook_');
            $this->assertSame($isWebhookSecret, ($field['section'] ?? null) === 'tracking', $field['key']);

            if ($isWebhookSecret) {
                $this->assertTrue($field['secret']);
                $this->assertFalse($field['required']);
            }
        }
    }

    /**
     * Build a descriptor provider over mocked HTTP collaborators.
     *
     * @param class-string<ProviderInterface> $class
     */
    private function make(string $class): ProviderInterface
    {
        return new $class(
            Mockery::mock(ApiClient::class),
            new AuthorizationResolver(Mockery::mock(OAuth2TokenProvider::class), new SigV4Signer())
        );
    }
}
