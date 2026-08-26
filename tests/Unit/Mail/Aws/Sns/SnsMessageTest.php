<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Aws\Sns;

use BitApps\SMTP\Mail\Aws\Sns\SnsMessage;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class SnsMessageTest extends BaseUnitTestCase
{
    public function testNotificationWithoutSubjectSignsByteSortedFieldsWithSingleTrailingNewline(): void
    {
        $message = SnsMessage::fromArray([
            'Type'      => 'Notification',
            'MessageId' => 'msg-1',
            'TopicArn'  => 'arn:aws:sns:us-east-1:1:t',
            'Message'   => 'hello',
            'Timestamp' => '2026-08-26T00:00:00Z',
            // Fields deliberately out of order to prove the canonical string is byte-sorted, not input-ordered.
        ]);

        $this->assertSame(
            "Message\nhello\n"
            . "MessageId\nmsg-1\n"
            . "Timestamp\n2026-08-26T00:00:00Z\n"
            . "TopicArn\narn:aws:sns:us-east-1:1:t\n"
            . "Type\nNotification\n",
            $message->stringToSign()
        );
    }

    public function testNotificationWithSubjectInsertsSubjectBetweenMessageIdAndTimestamp(): void
    {
        $message = SnsMessage::fromArray([
            'Type'      => 'Notification',
            'MessageId' => 'msg-1',
            'Subject'   => 'a subject',
            'TopicArn'  => 'arn:aws:sns:us-east-1:1:t',
            'Message'   => 'hello',
            'Timestamp' => '2026-08-26T00:00:00Z',
        ]);

        $this->assertSame(
            "Message\nhello\n"
            . "MessageId\nmsg-1\n"
            . "Subject\na subject\n"
            . "Timestamp\n2026-08-26T00:00:00Z\n"
            . "TopicArn\narn:aws:sns:us-east-1:1:t\n"
            . "Type\nNotification\n",
            $message->stringToSign()
        );
    }

    public function testSubscriptionConfirmationSignsSubscribeUrlAndTokenInByteSortOrder(): void
    {
        $message = SnsMessage::fromArray([
            'Type'         => 'SubscriptionConfirmation',
            'MessageId'    => 'msg-2',
            'Token'        => 'tok',
            'TopicArn'     => 'arn:aws:sns:us-east-1:1:t',
            'Message'      => 'confirm me',
            'SubscribeURL' => 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription',
            'Timestamp'    => '2026-08-26T00:00:00Z',
        ]);

        $this->assertSame(
            "Message\nconfirm me\n"
            . "MessageId\nmsg-2\n"
            . "SubscribeURL\nhttps://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription\n"
            . "Timestamp\n2026-08-26T00:00:00Z\n"
            . "Token\ntok\n"
            . "TopicArn\narn:aws:sns:us-east-1:1:t\n"
            . "Type\nSubscriptionConfirmation\n",
            $message->stringToSign()
        );
    }

    public function testTypePredicatesReflectTheTypeField(): void
    {
        $notification = SnsMessage::fromArray(['Type' => 'Notification']);
        $this->assertTrue($notification->isNotification());
        $this->assertFalse($notification->isSubscriptionConfirmation());

        $confirmation = SnsMessage::fromArray(['Type' => 'SubscriptionConfirmation']);
        $this->assertFalse($confirmation->isNotification());
        $this->assertTrue($confirmation->isSubscriptionConfirmation());
    }

    public function testAccessorsReturnTheirFieldValues(): void
    {
        $message = SnsMessage::fromArray([
            'SigningCertURL'   => 'https://sns.us-east-1.amazonaws.com/cert.pem',
            'Signature'        => 'sig==',
            'SignatureVersion' => '1',
            'SubscribeURL'     => 'https://sns.us-east-1.amazonaws.com/subscribe',
            'TopicArn'         => 'arn:aws:sns:us-east-1:1:t',
            'Message'          => 'body',
        ]);

        $this->assertSame('https://sns.us-east-1.amazonaws.com/cert.pem', $message->signingCertUrl());
        $this->assertSame('sig==', $message->signature());
        $this->assertSame('1', $message->signatureVersion());
        $this->assertSame('https://sns.us-east-1.amazonaws.com/subscribe', $message->subscribeUrl());
        $this->assertSame('arn:aws:sns:us-east-1:1:t', $message->topicArn());
        $this->assertSame('body', $message->message());
    }

    public function testAccessorsReturnEmptyStringWhenFieldIsAbsent(): void
    {
        $message = SnsMessage::fromArray([]);

        $this->assertSame('', $message->signingCertUrl());
        $this->assertSame('', $message->signature());
        $this->assertSame('', $message->signatureVersion());
        $this->assertSame('', $message->subscribeUrl());
        $this->assertSame('', $message->topicArn());
        $this->assertSame('', $message->message());
    }

    public function testNonScalarFieldValueIsTreatedAsEmptyString(): void
    {
        $message = SnsMessage::fromArray([
            'Message'   => ['nested' => 'array'],
            'Signature' => ['also', 'array'],
        ]);

        $this->assertSame('', $message->message());
        $this->assertSame('', $message->signature());
    }
}
