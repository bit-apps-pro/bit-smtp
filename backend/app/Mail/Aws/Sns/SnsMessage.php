<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Aws\Sns;

/**
 * A parsed Amazon SNS HTTP notification envelope, with the exact byte-sorted string-to-sign AWS
 * requires for signature verification. Parsing never throws; missing fields become '' so a malformed
 * body simply fails verification rather than fataling the public endpoint.
 */
final class SnsMessage
{
    public const TYPE_NOTIFICATION = 'Notification';

    public const TYPE_SUBSCRIPTION_CONFIRMATION = 'SubscriptionConfirmation';

    public const TYPE_UNSUBSCRIBE_CONFIRMATION = 'UnsubscribeConfirmation';

    /**
     * @var array<string,mixed>
     */
    private array $data;

    /**
     * @param array<string,mixed> $data
     */
    private function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * @param array<string,mixed> $decoded the JSON-decoded SNS request body
     */
    public static function fromArray(array $decoded): self
    {
        return new self($decoded);
    }

    public function type(): string
    {
        return $this->str('Type');
    }

    public function topicArn(): string
    {
        return $this->str('TopicArn');
    }

    /**
     * The AWS account id embedded in the TopicArn (`arn:aws:sns:<region>:<account>:<topic>`), or '' if
     * the ARN is malformed. Used to pin a connection to one AWS account: every SES notification topic
     * (bounce/complaint/delivery) for that account shares this id, so it blocks cross-account spoofing
     * without breaking SES's multi-topic model.
     */
    public function topicAccountId(): string
    {
        $parts = explode(':', $this->topicArn());

        return $parts[4] ?? '';
    }

    public function message(): string
    {
        return $this->str('Message');
    }

    public function signatureVersion(): string
    {
        return $this->str('SignatureVersion');
    }

    public function signature(): string
    {
        return $this->str('Signature');
    }

    public function signingCertUrl(): string
    {
        return $this->str('SigningCertURL');
    }

    public function subscribeUrl(): string
    {
        return $this->str('SubscribeURL');
    }

    public function isNotification(): bool
    {
        return $this->type() === self::TYPE_NOTIFICATION;
    }

    public function isSubscriptionConfirmation(): bool
    {
        return $this->type() === self::TYPE_SUBSCRIPTION_CONFIRMATION;
    }

    /**
     * The canonical string to sign: each required field as "key\nvalue\n" in byte-sort (alphabetical)
     * order, per the AWS "Verifying the signature of an Amazon SNS message" reference. A Notification's
     * optional Subject is included only when present; a confirmation adds SubscribeURL + Token.
     */
    public function stringToSign(): string
    {
        $fields = $this->isNotification()
            ? $this->notificationFields()
            : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];

        $out = '';
        foreach ($fields as $field) {
            $out .= $field . "\n" . $this->str($field) . "\n";
        }

        return $out;
    }

    /**
     * @return string[]
     */
    private function notificationFields(): array
    {
        $fields = ['Message', 'MessageId'];
        // Subject sits in its byte-sort position between MessageId and Timestamp, and only when present.
        if (\array_key_exists('Subject', $this->data)) {
            $fields[] = 'Subject';
        }

        return array_merge($fields, ['Timestamp', 'TopicArn', 'Type']);
    }

    private function str(string $key): string
    {
        return isset($this->data[$key]) && \is_scalar($this->data[$key]) ? (string) $this->data[$key] : '';
    }
}
