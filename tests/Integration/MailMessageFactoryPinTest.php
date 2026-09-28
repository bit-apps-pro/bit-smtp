<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Mail\Message\MailMessageFactory;
use BitApps\SMTP\Plugin;

/**
 * Pins MailMessageFactory resolution against live wp_mail() delivery for From/Content-Type.
 *
 * @internal
 *
 * @coversNothing
 */
final class MailMessageFactoryPinTest extends IntegrationTestCase
{
    private MailMessageFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new MailMessageFactory();
        $this->useRealPhpMailer();
        $this->configureMailpitTransport();
    }

    public function testFactoryResolutionMatchesLiveWpMailDelivery(): void
    {
        $atts = [
            'to'      => 'recipient@example.org',
            'subject' => 'Pinned Subject',
            'message' => '<p>Pinned body</p>',
            'headers' => implode("\r\n", [
                'From: Factory Sender <factory-sender@example.org>',
                'Content-Type: text/html; charset=UTF-8',
                'Reply-To: factory-reply@example.org',
            ]),
        ];

        $message = $this->factory->fromWpMailAtts($atts);

        $this->assertSame('factory-sender@example.org', $message->getFrom());
        $this->assertSame('Factory Sender', $message->getFromName());
        $this->assertSame('text/html', $message->getContentType());
        $this->assertSame('factory-reply@example.org', $message->getReplyTo());
        $this->assertSame([], $message->getHeaders());

        $sent = wp_mail($atts['to'], $atts['subject'], $atts['message'], $atts['headers']);
        $this->assertTrue($sent);

        $delivered = $this->latestMailpitMessage();
        $this->assertNotNull($delivered);

        $this->assertSame($message->getFrom(), $delivered['From']['Address']);
        $this->assertSame($message->getFromName(), $delivered['From']['Name']);
        $this->assertStringContainsStringIgnoringCase(
            $message->getContentType(),
            $this->latestMailpitContentType($delivered)
        );
        $this->assertSame($message->getReplyTo(), $delivered['ReplyTo'][0]['Address']);
    }

    /**
     * Configures mailpit SMTP with empty connection From so wp_mail header-derived From reaches wire.
     */
    private function configureMailpitTransport(): void
    {
        $this->storeOptions([
            'status'     => true,
            'smtp_host'  => self::SMTP_HOST,
            'port'       => self::SMTP_PORT,
            'encryption' => 'none',
            'smtp_auth'  => false,
        ]);

        Plugin::instance()->mailConfigService()->reload();
    }

    /**
     * @param array<string,mixed> $delivered
     */
    private function latestMailpitContentType(array $delivered): string
    {
        $response = wp_remote_get(self::MAILPIT_API . '/message/' . $delivered['ID'] . '/headers');
        $headers  = json_decode(wp_remote_retrieve_body($response), true);

        return isset($headers['Content-Type'][0]) ? (string) $headers['Content-Type'][0] : '';
    }
}
