<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Providers;

use BitApps\SMTP\Mail\Contracts\WebhookProvisionerInterface;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Providers\SendGrid\SendGridWebhookService;

/**
 * Resolves the API webhook provisioner for a provider, or null when that provider cannot create its
 * own webhook. Single source of truth for the "provisions its own webhook" capability.
 */
final class WebhookProvisionerFactory
{
    /**
     * @var array<string, class-string<WebhookProvisionerInterface>>
     */
    private const PROVISIONERS = [
        'sendgrid' => SendGridWebhookService::class,
    ];

    public static function supportsProvider(string $provider): bool
    {
        return isset(self::PROVISIONERS[$provider]);
    }

    public function forProvider(string $provider, ApiClient $client): ?WebhookProvisionerInterface
    {
        $class = self::PROVISIONERS[$provider] ?? null;

        return $class !== null ? new $class($client) : null;
    }
}
