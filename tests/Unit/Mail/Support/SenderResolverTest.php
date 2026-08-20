<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Support;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Support\SenderResolver;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class SenderResolverTest extends BaseUnitTestCase
{
    private SenderResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new SenderResolver();
    }

    public function testFromPrefersMessageSenderOverConnection(): void
    {
        $message = $this->message(['from' => 'msg@example.com', 'fromName' => 'Message Sender']);

        $this->assertSame(
            ['Message Sender <msg@example.com>'],
            $this->resolver->from($message, $this->connection())
        );
    }

    public function testFromFallsBackToConnectionWhenMessageSenderAbsent(): void
    {
        $this->assertSame(
            ['Conn Sender <conn@example.com>'],
            $this->resolver->from($this->message(), $this->connection())
        );
    }

    public function testFromOmitsNameWhenNoneAvailable(): void
    {
        $message = $this->message(['from' => 'msg@example.com']);

        $this->assertSame(['msg@example.com'], $this->resolver->from($message, $this->connection()));
    }

    public function testFromReturnsEmptyWhenNeitherMessageNorConnectionHasSender(): void
    {
        $connection = Connection::fromArray(['id' => 'c', 'provider' => 'x', 'kind' => 'api']);

        $this->assertSame([], $this->resolver->from($this->message(), $connection));
    }

    public function testFromUsesMessageNameWithMessageEmailNotConnectionName(): void
    {
        $message = $this->message(['from' => 'msg@example.com', 'fromName' => 'Message Sender']);

        // Connection name must not leak onto the message's own address.
        $this->assertSame(
            ['Message Sender <msg@example.com>'],
            $this->resolver->from($message, $this->connection())
        );
    }

    public function testReplyToPrefersMessageOverConnection(): void
    {
        $message = $this->message(['replyTo' => 'msg-reply@example.com']);

        $this->assertSame(['msg-reply@example.com'], $this->resolver->replyTo($message, $this->connection()));
    }

    public function testReplyToFallsBackToConnection(): void
    {
        $connection = $this->connection(['replyToEmail' => 'conn-reply@example.com']);

        $this->assertSame(['conn-reply@example.com'], $this->resolver->replyTo($this->message(), $connection));
    }

    public function testReplyToNeverIncludesADisplayName(): void
    {
        $message = $this->message(['replyTo' => 'msg-reply@example.com', 'fromName' => 'Message Sender']);

        $this->assertSame(['msg-reply@example.com'], $this->resolver->replyTo($message, $this->connection()));
    }

    public function testReplyToReturnsEmptyWhenNeitherHasIt(): void
    {
        $this->assertSame([], $this->resolver->replyTo($this->message(), $this->connection()));
    }

    private function message(array $overrides = []): MailMessage
    {
        return MailMessage::fromArray(array_merge([
            'to' => ['to@example.com'], 'subject' => 'Subject', 'body' => 'Body',
        ], $overrides));
    }

    private function connection(array $overrides = []): Connection
    {
        return Connection::fromArray(array_merge([
            'id'        => 'conn_1', 'provider' => 'x', 'kind' => 'api',
            'fromEmail' => 'conn@example.com', 'fromName' => 'Conn Sender',
        ], $overrides));
    }
}
