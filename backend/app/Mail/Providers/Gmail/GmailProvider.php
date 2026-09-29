<?php

namespace BitApps\SMTP\Mail\Providers\Gmail;

use BitApps\SMTP\Mail\Contracts\ProviderInterface;
use BitApps\SMTP\Mail\Contracts\TransportInterface;
use BitApps\SMTP\Mail\Contracts\ValidatorInterface;
use BitApps\SMTP\Mail\Providers\ProviderGuide;

class GmailProvider implements ProviderInterface
{
    private TransportInterface $transport;

    private ?ValidatorInterface $validator = null;

    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
    }

    public function key(): string
    {
        return 'gmail';
    }

    public function label(): string
    {
        return 'Gmail / Google Workspace';
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
            $this->validator = new GmailValidator();
        }

        return $this->validator;
    }

    public function transport(): TransportInterface
    {
        return $this->transport;
    }

    public function authConfig(): array
    {
        return ['type' => 'oauth2', 'params' => []];
    }

    public function tracking(): array
    {
        return [];
    }

    /**
     * Define connection fields; refresh_token is set by OAuth flow, not user-entered.
     */
    public function fields(): array
    {
        return [
            [
                'key'         => 'client_id', 'label' => __('OAuth client ID', 'bit-smtp'), 'type' => 'text', 'required' => true, 'secret' => false,
                'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null,
                'help'        => [
                    'text'      => __('From Google Cloud > Credentials > your "Web application" OAuth client.', 'bit-smtp'),
                    'url'       => 'https://developers.google.com/workspace/guides/create-credentials#oauth-client-id',
                    'linkLabel' => __('Where do I find this?', 'bit-smtp'),
                ],
            ],
            [
                'key'         => 'client_secret', 'label' => __('OAuth client secret', 'bit-smtp'), 'type' => 'password', 'required' => true, 'secret' => true,
                'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null,
                'help'        => [
                    'text'      => __('Shown next to the client ID. Paste the whole value.', 'bit-smtp'),
                    'url'       => 'https://developers.google.com/workspace/guides/create-credentials#oauth-client-id',
                    'linkLabel' => __('Where do I find this?', 'bit-smtp'),
                ],
            ],
            [
                'key'         => 'oauth', 'label' => __('Google account', 'bit-smtp'), 'type' => 'oauth', 'required' => false, 'secret' => false,
                'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null,
                'help'        => [
                    'text'      => __('Sign in with the Google account you entered as From Email.', 'bit-smtp'),
                    'url'       => 'https://developers.google.com/workspace/guides/configure-oauth-consent',
                    'linkLabel' => __('Configure the OAuth consent screen', 'bit-smtp'),
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
            group: ProviderGuide::GROUP_MAILBOX,
            bestFor: __('Low-volume mail from a Gmail or Google Workspace mailbox you already own.', 'bit-smtp'),
            docUrl: 'https://developers.google.com/identity/protocols/oauth2/web-server',
            fromEmailHint: __('Use the Google account you sign in with, or an alias set up in its Gmail settings.', 'bit-smtp'),
            prerequisites: [
                ProviderGuide::step(
                    'enable_gmail_api',
                    __('Enable the Gmail API', 'bit-smtp'),
                    __('In Google Cloud, create or choose a project and enable the Gmail API for it.', 'bit-smtp'),
                    'https://support.google.com/googleapi/answer/6158841'
                ),
                ProviderGuide::step(
                    'publish_consent_screen',
                    __('Publish the OAuth consent screen', 'bit-smtp'),
                    __('Set it to In production (or Internal for Workspace), or Google signs you out after 7 days.', 'bit-smtp'),
                    'https://support.google.com/cloud/answer/15549945'
                ),
                ProviderGuide::step(
                    'create_oauth_client',
                    __('Create a Web application OAuth client', 'bit-smtp'),
                    __('Create a Web application client and add the Redirect URI shown below to it.', 'bit-smtp'),
                    'https://developers.google.com/identity/protocols/oauth2/web-server'
                ),
            ],
        );
    }
}
