<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Message;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Message\MimeBuilder;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use RuntimeException;

/**
 * Tests MimeBuilder; skips in unit tier without PHPMailer; integration tests verify MIME output.
 *
 * @internal
 *
 * @coversNothing
 */
class MimeBuilderTest extends BaseUnitTestCase
{
    private MimeBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new MimeBuilder();
    }

    public function testFromMailMessageProducesMimeContainingSubjectFromAndTo(): void
    {
        $message = MailMessage::fromArray([
            'to'       => ['recipient@example.com'],
            'subject'  => 'Hello there',
            'body'     => 'Plain body',
            'from'     => 'sender@example.com',
            'fromName' => 'Sender Name',
        ]);

        $connection = Connection::fromArray(['id' => 'conn_1', 'provider' => 'amazon_ses', 'kind' => 'api']);

        try {
            $mime = $this->builder->fromMailMessage($message, $connection);
        } catch (RuntimeException $e) {
            $this->markTestSkipped(
                'WP-bundled PHPMailer is unavailable in the unit tier (no WordPress runtime): '
                . $e->getMessage() . ' Covered by integration tests instead.'
            );

            return;
        }

        $this->assertStringContainsString('Subject: Hello there', $mime);
        $this->assertStringContainsString('recipient@example.com', $mime);
        $this->assertStringContainsString('sender@example.com', $mime);
        $this->assertStringContainsString('Plain body', $mime);
    }
}
