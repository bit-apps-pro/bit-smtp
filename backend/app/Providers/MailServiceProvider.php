<?php

namespace BitApps\SMTP\Providers;

use BitApps\SMTP\Deps\BitApps\WPKit\Container\Container;
use BitApps\SMTP\Deps\BitApps\WPKit\Container\ServiceProvider;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Aws\SigV4Signer;
use BitApps\SMTP\Mail\Connections\ConnectionResolver;
use BitApps\SMTP\Mail\Credentials\DatabaseCredentialResolver;
use BitApps\SMTP\Mail\Dispatch\WpMailBridge;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Message\MailMessageFactory;
use BitApps\SMTP\Mail\Message\MimeBuilder;
use BitApps\SMTP\Mail\Notifications\FailureNotifier;
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
use BitApps\SMTP\Mail\Providers\ProviderRegistry;
use BitApps\SMTP\Mail\Providers\Resend\ResendProvider;
use BitApps\SMTP\Mail\Providers\SendGrid\SendGridProvider;
use BitApps\SMTP\Mail\Providers\SendGrid\SendGridTransport;
use BitApps\SMTP\Mail\Providers\SparkPost\SparkPostProvider;
use BitApps\SMTP\Mail\Providers\Zepto\ZeptoProvider;
use BitApps\SMTP\Mail\Routing\MailSourceDetector;
use BitApps\SMTP\Mail\Routing\RoutingResolver;
use BitApps\SMTP\Mail\Transport\PhpSendmailTransport;
use BitApps\SMTP\Mail\Transport\SmtpTransport;
use BitApps\SMTP\Settings\PluginSettings;

/**
 * Binds the mail-sending stack: shared HTTP/auth/mime deps, all 14 provider registrations, and the
 * wp_mail bridge that drives them.
 */
class MailServiceProvider extends ServiceProvider
{
    /**
     * Register mail singletons; pure wiring, no side effects (WpMailBridge's hook registration is
     * deferred to boot()).
     */
    public function register(): void
    {
        $this->app->singleton(MimeBuilder::class, static fn (): MimeBuilder => new MimeBuilder());

        $this->app->singleton(
            OAuth2TokenProvider::class,
            static fn (Container $app): OAuth2TokenProvider => new OAuth2TokenProvider(
                $app->make(ApiClient::class),
                $app->make(MailConfigService::class)
            )
        );

        $this->app->singleton(SigV4Signer::class, static fn (): SigV4Signer => new SigV4Signer());

        $this->app->singleton(
            ProviderRegistry::class,
            static fn (Container $app): ProviderRegistry => self::buildProviderRegistry($app)
        );

        $this->app->singleton(
            WpMailBridge::class,
            static fn (Container $app): WpMailBridge => new WpMailBridge(
                $app->make(ProviderRegistry::class),
                new ConnectionResolver(),
                new MailMessageFactory(),
                new RoutingResolver(),
                new MailSourceDetector(),
                $app->make(FailureNotifier::class)
            )
        );
    }

    /**
     * Force-resolve WpMailBridge so its constructor registers pre_wp_mail / wp_mail_succeeded /
     * wp_mail_failed. A lazy singleton nothing ever calls make() on would silently never hook into
     * wp_mail, so this cannot be left to on-demand resolution.
     */
    public function boot(): void
    {
        $this->app->make(WpMailBridge::class);
    }

    /**
     * Builds the same 14 providers, in the same order and with the same shared dependencies, as the
     * legacy Plugin::registerProviders() wiring.
     */
    private static function buildProviderRegistry(Container $app): ProviderRegistry
    {
        $sendTimeoutSeconds = (int) PluginSettings::make()->get('send_timeout_seconds', 30);

        // A clone (not the shared singleton, see ApiClient::withTimeout()) so every API transport
        // built below fails within the configured window instead of hanging on a dead host; other
        // ApiClient consumers (e.g. OAuth2TokenProvider) keep resolving the untouched singleton.
        $apiClient     = $app->make(ApiClient::class)->withTimeout($sendTimeoutSeconds);
        $mimeBuilder   = $app->make(MimeBuilder::class);
        $tokenProvider = $app->make(OAuth2TokenProvider::class);
        $sigV4Signer   = $app->make(SigV4Signer::class);
        $authResolver  = $app->make(AuthorizationResolver::class);

        $registry = new ProviderRegistry();
        $registry->register(new OtherSmtpProvider(new SmtpTransport(new DatabaseCredentialResolver(), $sendTimeoutSeconds)));
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
