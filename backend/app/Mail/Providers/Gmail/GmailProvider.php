<?php

namespace BitApps\SMTP\Mail\Providers\Gmail;

use BitApps\SMTP\Mail\Contracts\ProviderInterface;
use BitApps\SMTP\Mail\Contracts\TransportInterface;
use BitApps\SMTP\Mail\Contracts\ValidatorInterface;

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
                'key'         => 'client_id', 'label' => 'Client ID', 'type' => 'text', 'required' => true, 'secret' => false,
                'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null,
                'help'        => [
                    'text'      => 'In Google Cloud, enable the Gmail API, configure the OAuth consent screen, then create an OAuth client ID of type Web application. Add the redirect URL shown in this form as an authorized redirect URI.',
                    'url'       => 'https://developers.google.com/workspace/guides/create-credentials#oauth-client-id',
                    'linkLabel' => 'Create an OAuth client ID',
                ],
            ],
            [
                'key'         => 'client_secret', 'label' => 'Client Secret', 'type' => 'password', 'required' => true, 'secret' => true,
                'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null,
                'help'        => [
                    'text'      => 'Copy the client secret from the same OAuth client in Google Cloud Credentials.',
                    'url'       => 'https://developers.google.com/workspace/guides/create-credentials#oauth-client-id',
                    'linkLabel' => 'Create OAuth credentials',
                ],
            ],
            [
                'key'         => 'oauth', 'label' => 'Google / Google Workspace account', 'type' => 'oauth', 'required' => false, 'secret' => false,
                'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null,
                'help'        => [
                    'text'      => 'Sign in with the Google account you send from and approve the gmail.send permission. While the consent screen is in Testing mode, add this account as a test user first.',
                    'url'       => 'https://developers.google.com/workspace/guides/configure-oauth-consent',
                    'linkLabel' => 'Configure the OAuth consent screen',
                ],
            ],
        ];
    }
}
