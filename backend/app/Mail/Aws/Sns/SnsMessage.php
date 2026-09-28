<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Aws\Sns;

/**
 * Parsed Amazon SNS notification with byte-sorted string-to-sign for signature verification.
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
     * AWS account id from TopicArn; pins connection to one account to prevent cross-account spoofing.
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
     * Canonical string to sign in byte-sort order per AWS SNS signature verification spec.
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
     * Notification fields in byte-sort order including optional Subject.
     *
     * @return string[]
     */
    private function notificationFields(): array
    {
        $fields = ['Message', 'MessageId'];
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
