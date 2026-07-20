<?php

namespace BitApps\SMTP\Mail\Providers\Microsoft365;

use BitApps\SMTP\Mail\Contracts\ProviderInterface;
use BitApps\SMTP\Mail\Contracts\TransportInterface;
use BitApps\SMTP\Mail\Contracts\ValidatorInterface;

class Microsoft365Provider implements ProviderInterface
{
    private TransportInterface $transport;

    private ?ValidatorInterface $validator = null;

    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
    }

    public function key(): string
    {
        return 'microsoft365';
    }

    public function label(): string
    {
        return 'Microsoft 365 / Outlook';
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
            $this->validator = new Microsoft365Validator();
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
     * access_token/refresh_token are deliberately absent — they are not user-entered fields but are
     * set by the OAuth consent flow and stored as credentials. The `oauth` entry is a render-only
     * marker so the editor shows a Connect control instead of an input.
     */
    public function fields(): array
    {
        return [
            [
                'key'         => 'client_id', 'label' => 'Application (client) ID', 'type' => 'text', 'required' => true, 'secret' => false,
                'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null,
            ],
            [
                'key'         => 'client_secret', 'label' => 'Client Secret', 'type' => 'password', 'required' => true, 'secret' => true,
                'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null,
            ],
            [
                'key'         => 'tenant', 'label' => 'Directory (tenant) ID', 'type' => 'text', 'required' => false, 'secret' => false,
                'placeholder' => 'common', 'default' => 'common', 'options' => [], 'dependsOn' => null,
            ],
            [
                'key'         => 'oauth', 'label' => 'Microsoft account', 'type' => 'oauth', 'required' => false, 'secret' => false,
                'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null,
            ],
        ];
    }
}
