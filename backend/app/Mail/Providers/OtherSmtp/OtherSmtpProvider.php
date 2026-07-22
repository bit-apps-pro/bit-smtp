<?php

namespace BitApps\SMTP\Mail\Providers\OtherSmtp;

use BitApps\SMTP\Mail\Contracts\ProviderInterface;
use BitApps\SMTP\Mail\Contracts\TransportInterface;
use BitApps\SMTP\Mail\Contracts\ValidatorInterface;

class OtherSmtpProvider implements ProviderInterface
{
    private TransportInterface $transport;

    private ?ValidatorInterface $validator = null;

    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
    }

    public function key(): string
    {
        return 'other_smtp';
    }

    public function label(): string
    {
        return 'Other SMTP';
    }

    public function kind(): string
    {
        return 'smtp';
    }

    public function defaults(): array
    {
        return [
            'port'       => 587,
            'encryption' => 'tls',
            'auth'       => true,
        ];
    }

    public function validator(): ValidatorInterface
    {
        if ($this->validator === null) {
            $this->validator = new OtherSmtpValidator();
        }

        return $this->validator;
    }

    public function transport(): TransportInterface
    {
        return $this->transport;
    }

    public function authConfig(): array
    {
        return ['type' => 'smtp', 'params' => []];
    }

    public function tracking(): array
    {
        return [];
    }

    public function deliveryStatusOnAccept(): ?string
    {
        return null;
    }

    public function fields(): array
    {
        $dependsOnAuth = ['field' => 'auth', 'value' => true];

        return [
            [
                'key'         => 'host', 'label' => 'SMTP Host', 'type' => 'text', 'required' => true, 'secret' => false,
                'placeholder' => 'smtp.example.com', 'default' => '', 'options' => [], 'dependsOn' => null,
            ],
            [
                'key'         => 'port', 'label' => 'SMTP Port', 'type' => 'number', 'required' => true, 'secret' => false,
                'placeholder' => '', 'default' => 587, 'options' => [], 'dependsOn' => null,
            ],
            [
                'key'         => 'encryption', 'label' => 'Encryption', 'type' => 'select', 'required' => true, 'secret' => false,
                'placeholder' => '', 'default' => 'tls',
                'options'     => [
                    ['value' => 'none', 'label' => 'None'],
                    ['value' => 'ssl', 'label' => 'SSL'],
                    ['value' => 'tls', 'label' => 'TLS'],
                ],
                'dependsOn' => null,
            ],
            [
                'key'         => 'auth', 'label' => 'Authentication', 'type' => 'switch', 'required' => false, 'secret' => false,
                'placeholder' => '', 'default' => true, 'options' => [], 'dependsOn' => null,
            ],
            [
                'key'         => 'username', 'label' => 'Username', 'type' => 'text', 'required' => false, 'secret' => false,
                'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => $dependsOnAuth,
            ],
            [
                'key'         => 'password', 'label' => 'Password', 'type' => 'password', 'required' => false, 'secret' => true,
                'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => $dependsOnAuth,
            ],
        ];
    }
}
