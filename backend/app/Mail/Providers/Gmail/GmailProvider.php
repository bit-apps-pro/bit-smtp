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

    /**
     * refresh_token is deliberately absent here — it's not a user-entered field, it's set by
     * the OAuth consent flow and stored as a credential. The `oauth` entry is a render-only
     * marker so the editor knows to show a Connect control instead of an input for it.
     *
     * Google Workspace (formerly G Suite) accounts connect through this same Gmail OAuth flow —
     * no separate provider is required; the field copy reflects that.
     */
    public function fields(): array
    {
        return [
            [
                'key'         => 'client_id', 'label' => 'Client ID', 'type' => 'text', 'required' => true, 'secret' => false,
                'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null,
            ],
            [
                'key'         => 'client_secret', 'label' => 'Client Secret', 'type' => 'password', 'required' => true, 'secret' => true,
                'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null,
            ],
            [
                'key'         => 'oauth', 'label' => 'Google / Google Workspace account', 'type' => 'oauth', 'required' => false, 'secret' => false,
                'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null,
            ],
        ];
    }
}
