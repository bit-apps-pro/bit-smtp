<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Providers;

use BitApps\SMTP\Mail\Contracts\AuthStrategyInterface;
use BitApps\SMTP\Mail\Contracts\WebhookProvisionerInterface;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Providers\Brevo\BrevoWebhookService;
use BitApps\SMTP\Mail\Providers\Mailgun\MailgunWebhookService;
use BitApps\SMTP\Mail\Providers\Mailjet\MailjetWebhookService;
use BitApps\SMTP\Mail\Providers\Postmark\PostmarkWebhookService;
use BitApps\SMTP\Mail\Providers\Resend\ResendWebhookService;
use BitApps\SMTP\Mail\Providers\SendGrid\SendGridWebhookService;
use BitApps\SMTP\Mail\Providers\SparkPost\SparkPostWebhookService;

/**
 * Resolve webhook provisioners; ZeptoMail omitted (no public registration API).
 */
class WebhookProvisionerFactory
{
    /**
     * @var array<string, class-string<WebhookProvisionerInterface>>
     */
    private const PROVISIONERS = [
        'sendgrid'  => SendGridWebhookService::class,
        'brevo'     => BrevoWebhookService::class,
        'postmark'  => PostmarkWebhookService::class,
        'sparkpost' => SparkPostWebhookService::class,
        'mailgun'   => MailgunWebhookService::class,
        'mailjet'   => MailjetWebhookService::class,
        'resend'    => ResendWebhookService::class,
    ];

    public static function supportsProvider(string $provider): bool
    {
        return isset(self::PROVISIONERS[$provider]);
    }

    public function forProvider(string $provider, ApiClient $client, AuthStrategyInterface $auth): ?WebhookProvisionerInterface
    {
        $class = self::PROVISIONERS[$provider] ?? null;

        return $class !== null ? new $class($client, $auth) : null;
    }
}
