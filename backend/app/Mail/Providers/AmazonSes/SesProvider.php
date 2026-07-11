<?php

namespace BitApps\SMTP\Mail\Providers\AmazonSes;

use BitApps\SMTP\Mail\Contracts\ProviderInterface;
use BitApps\SMTP\Mail\Contracts\TransportInterface;
use BitApps\SMTP\Mail\Contracts\ValidatorInterface;

class SesProvider implements ProviderInterface
{
    private TransportInterface $transport;

    private ?ValidatorInterface $validator = null;

    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
    }

    public function key(): string
    {
        return 'amazon_ses';
    }

    public function label(): string
    {
        return 'Amazon SES';
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
            $this->validator = new SesValidator();
        }

        return $this->validator;
    }

    public function transport(): TransportInterface
    {
        return $this->transport;
    }

    public function fields(): array
    {
        return [
            [
                'key'         => 'access_key', 'label' => 'Access Key ID', 'type' => 'text', 'required' => true, 'secret' => false,
                'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null,
            ],
            [
                'key'         => 'secret_key', 'label' => 'Secret Access Key', 'type' => 'password', 'required' => true, 'secret' => true,
                'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null,
            ],
            [
                'key'         => 'region', 'label' => 'Region', 'type' => 'select', 'required' => true, 'secret' => false,
                'placeholder' => '', 'default' => 'us-east-1',
                'options'     => [
                    ['value' => 'us-east-1', 'label' => 'US East (N. Virginia)'],
                    ['value' => 'us-east-2', 'label' => 'US East (Ohio)'],
                    ['value' => 'us-west-2', 'label' => 'US West (Oregon)'],
                    ['value' => 'eu-west-1', 'label' => 'EU (Ireland)'],
                    ['value' => 'eu-central-1', 'label' => 'EU (Frankfurt)'],
                    ['value' => 'ap-south-1', 'label' => 'Asia Pacific (Mumbai)'],
                    ['value' => 'ap-southeast-2', 'label' => 'Asia Pacific (Sydney)'],
                ],
                'dependsOn' => null,
            ],
        ];
    }
}
