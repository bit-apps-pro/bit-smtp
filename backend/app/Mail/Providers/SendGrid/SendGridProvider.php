<?php

namespace BitApps\SMTP\Mail\Providers\SendGrid;

use BitApps\SMTP\Mail\Contracts\ProviderInterface;
use BitApps\SMTP\Mail\Contracts\TransportInterface;
use BitApps\SMTP\Mail\Contracts\ValidatorInterface;
use BitApps\SMTP\Mail\Dispatch\TrackingIdStamper;
use BitApps\SMTP\Mail\Providers\ProviderGuide;

class SendGridProvider implements ProviderInterface
{
    private TransportInterface $transport;

    private ?ValidatorInterface $validator = null;

    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
    }

    public function key(): string
    {
        return 'sendgrid';
    }

    public function label(): string
    {
        return 'SendGrid';
    }

    public function kind(): string
    {
        return 'api';
    }

    public function defaults(): array
    {
        return [];
    }

    public function validator(): ValidatorInterface
    {
        if ($this->validator === null) {
            $this->validator = new SendGridValidator();
        }

        return $this->validator;
    }

    public function transport(): TransportInterface
    {
        return $this->transport;
    }

    public function authConfig(): array
    {
        return ['type' => 'bearer', 'params' => ['credentialKey' => 'api_key']];
    }

    public function tracking(): array
    {
        return ['channel' => 'metadata', 'key' => TrackingIdStamper::METADATA_KEY];
    }

    /**
     * Define the single API key field.
     */
    public function fields(): array
    {
        return [
            [
                'key'         => 'api_key', 'label' => __('API key', 'bit-smtp'), 'type' => 'password', 'required' => true, 'secret' => true,
                'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null,
                'help'        => [
                    'text'      => __('Create one under Settings > API Keys with at least Mail Send access.', 'bit-smtp'),
                    'url'       => 'https://www.twilio.com/docs/sendgrid/ui/account-and-settings/api-keys',
                    'linkLabel' => __('Where do I find this?', 'bit-smtp'),
                ],
            ],
        ];
    }

    /**
     * Setup guide shown when choosing and configuring this provider.
     */
    public function guide(): array
    {
        return ProviderGuide::make(
            group: ProviderGuide::GROUP_TRANSACTIONAL,
            bestFor: __('Transactional and marketing mail at any volume.', 'bit-smtp'),
            docUrl: 'https://www.twilio.com/docs/sendgrid/ui/account-and-settings/api-keys',
            fromEmailHint: __('Must be a verified single sender or on an authenticated domain in SendGrid.', 'bit-smtp'),
            prerequisites: [
                ProviderGuide::step(
                    'sender_authentication',
                    __('Authenticate your domain or a single sender', 'bit-smtp'),
                    __('Verifying your whole domain works best; verifying one address is fine for small sites.', 'bit-smtp'),
                    'https://www.twilio.com/docs/sendgrid/ui/account-and-settings/how-to-set-up-domain-authentication'
                ),
                ProviderGuide::step(
                    'api_key',
                    __('Create an API key', 'bit-smtp'),
                    __('Mail Send access is enough to send; delivery tracking needs more access.', 'bit-smtp'),
                    'https://www.twilio.com/docs/sendgrid/ui/account-and-settings/api-keys'
                ),
            ],
        );
    }
}
