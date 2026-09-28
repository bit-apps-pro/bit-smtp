<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Webhook;

use BitApps\SMTP\Mail\Webhook\WebhookAdapterFactory;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class WebhookAdapterFactoryTest extends BaseUnitTestCase
{
    public function testEveryDocumentedHttpWebhookProviderResolvesAnAdapter(): void
    {
        $factory = new WebhookAdapterFactory();

        foreach (['postmark', 'brevo', 'sendgrid', 'mailgun', 'resend', 'mailjet', 'sparkpost', 'zeptomail', 'amazon_ses'] as $provider) {
            $this->assertNotNull($factory->forProvider($provider), $provider);
        }
    }

    public function testProvidersWithoutDeliveryWebhookContractReturnNull(): void
    {
        $factory = new WebhookAdapterFactory();

        foreach (['gmail', 'microsoft365', 'other_smtp', 'unknown'] as $provider) {
            $this->assertNull($factory->forProvider($provider), $provider);
        }
    }
}
