<?php

namespace BitApps\SMTP\Mail\Providers\AmazonSes;

use BitApps\SMTP\Mail\Contracts\ProviderInterface;
use BitApps\SMTP\Mail\Contracts\TransportInterface;
use BitApps\SMTP\Mail\Contracts\ValidatorInterface;
use BitApps\SMTP\Mail\Providers\ProviderGuide;

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

    public function authConfig(): array
    {
        return ['type' => 'aws_sigv4', 'params' => ['service' => 'ses']];
    }

    public function tracking(): array
    {
        return [];
    }

    /**
     * Define SES fields, region first: identities and sandbox status are per region.
     */
    public function fields(): array
    {
        return [
            [
                'key'         => 'region', 'label' => __('SES region', 'bit-smtp'), 'type' => 'select', 'required' => true, 'secret' => false,
                'placeholder' => '', 'default' => 'us-east-1', 'options' => self::regionOptions(), 'dependsOn' => null,
                'help'        => [
                    'text'      => __('Pick the region where you verified your domain in Amazon SES.', 'bit-smtp'),
                    'url'       => 'https://docs.aws.amazon.com/ses/latest/dg/regions.html',
                    'linkLabel' => __('See SES regions', 'bit-smtp'),
                ],
            ],
            [
                'key'         => 'access_key', 'label' => __('Access key ID', 'bit-smtp'), 'type' => 'text', 'required' => true, 'secret' => false,
                'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null,
                'help'        => [
                    'text'      => __('From an AWS (IAM) user that is only allowed to send email.', 'bit-smtp'),
                    'url'       => 'https://docs.aws.amazon.com/ses/latest/dg/control-user-access.html',
                    'linkLabel' => __('Set up a send-only user', 'bit-smtp'),
                ],
            ],
            [
                'key'         => 'secret_key', 'label' => __('Secret access key', 'bit-smtp'), 'type' => 'password', 'required' => true, 'secret' => true,
                'placeholder' => '', 'default' => '', 'options' => [], 'dependsOn' => null,
                'help'        => [
                    'text'      => __('Shown once when you create the access key. Lost it? Create a new key.', 'bit-smtp'),
                    'url'       => 'https://docs.aws.amazon.com/IAM/latest/UserGuide/id_credentials_access-keys.html',
                    'linkLabel' => __('Manage IAM access keys', 'bit-smtp'),
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
            bestFor: __('High volume at low cost, if you are comfortable with AWS.', 'bit-smtp'),
            docUrl: 'https://docs.aws.amazon.com/ses/latest/dg/creating-identities.html',
            fromEmailHint: __('Must be an address or domain verified in SES, in the region selected below.', 'bit-smtp'),
            testCaveat: __('In the SES sandbox this test can pass while emails to customers fail, so request production access.', 'bit-smtp'),
            prerequisites: [
                ProviderGuide::step(
                    'verify_identity',
                    __('Verify your domain or sender address', 'bit-smtp'),
                    __('Verify it in the same AWS region you select here.', 'bit-smtp'),
                    'https://docs.aws.amazon.com/ses/latest/dg/creating-identities.html'
                ),
                ProviderGuide::step(
                    'production_access',
                    __('Request production access', 'bit-smtp'),
                    __('New accounts can only email verified addresses, up to 200 a day, until you ask AWS to lift this.', 'bit-smtp'),
                    'https://docs.aws.amazon.com/ses/latest/dg/request-production-access.html'
                ),
                ProviderGuide::step(
                    'iam_user',
                    __('Create a dedicated IAM user', 'bit-smtp'),
                    __('Give it permission to send email only, and never use your main (root) account keys.', 'bit-smtp'),
                    'https://docs.aws.amazon.com/IAM/latest/UserGuide/id_credentials_access-keys.html'
                ),
            ],
        );
    }

    /**
     * Commercial SES API regions (docs.aws.amazon.com/general/latest/gr/ses.html) as select options.
     *
     * @return list<array{value: string, label: string}>
     */
    private static function regionOptions(): array
    {
        $regions = [
            'us-east-1'      => 'US East (N. Virginia)',
            'us-east-2'      => 'US East (Ohio)',
            'us-west-1'      => 'US West (N. California)',
            'us-west-2'      => 'US West (Oregon)',
            'af-south-1'     => 'Africa (Cape Town)',
            'ap-south-1'     => 'Asia Pacific (Mumbai)',
            'ap-south-2'     => 'Asia Pacific (Hyderabad)',
            'ap-southeast-1' => 'Asia Pacific (Singapore)',
            'ap-southeast-2' => 'Asia Pacific (Sydney)',
            'ap-southeast-3' => 'Asia Pacific (Jakarta)',
            'ap-southeast-5' => 'Asia Pacific (Malaysia)',
            'ap-northeast-1' => 'Asia Pacific (Tokyo)',
            'ap-northeast-2' => 'Asia Pacific (Seoul)',
            'ap-northeast-3' => 'Asia Pacific (Osaka)',
            'ca-central-1'   => 'Canada (Central)',
            'ca-west-1'      => 'Canada West (Calgary)',
            'eu-central-1'   => 'Europe (Frankfurt)',
            'eu-central-2'   => 'Europe (Zurich)',
            'eu-west-1'      => 'Europe (Ireland)',
            'eu-west-2'      => 'Europe (London)',
            'eu-west-3'      => 'Europe (Paris)',
            'eu-south-1'     => 'Europe (Milan)',
            'eu-north-1'     => 'Europe (Stockholm)',
            'il-central-1'   => 'Israel (Tel Aviv)',
            'me-south-1'     => 'Middle East (Bahrain)',
            'me-central-1'   => 'Middle East (UAE)',
            'sa-east-1'      => 'South America (São Paulo)',
        ];

        return array_map(
            static fn (string $code, string $name): array => ['value' => $code, 'label' => \sprintf('%s — %s', $name, $code)],
            array_keys($regions),
            $regions
        );
    }
}
