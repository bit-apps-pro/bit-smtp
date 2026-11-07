<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Mail\Credentials\DatabaseCredentialResolver;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Transport\SmtpTransport;
use BitApps\SMTP\Plugin;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Sends real SMTP to Mailpit and inspects the raw message for charset and content-type fidelity.
 *
 * @internal
 *
 * @coversNothing
 */
final class MailContentEncodingTest extends IntegrationTestCase
{
    private const DIACRITICS = 'Příliš žluťoučký kůň';

    private const BIT_FORM_CONTENT_TYPE = 'text/html; charset=UTF-8';

    private const MULTIPART_BODY = "--b-1\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
        . self::DIACRITICS . " plain\r\n--b-1\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n<p>"
        . self::DIACRITICS . " html</p>\r\n--b-1--\r\n";

    /**
     * @var array<int,array{0:string,1:callable}>
     */
    private array $filters = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->useRealPhpMailer();
        $this->configureMailpitTransport();
    }

    protected function tearDown(): void
    {
        foreach ($this->filters as [$hook, $callback]) {
            remove_filter($hook, $callback);
        }

        parent::tearDown();
    }

    public function testBitFormFilteredHtmlIsDeliveredAsUtf8Html(): void
    {
        $this->addFilter('wp_mail_content_type', static fn (): string => self::BIT_FORM_CONTENT_TYPE);

        $this->assertTrue(wp_mail('to@example.org', self::DIACRITICS, '<p>' . self::DIACRITICS . '</p>'));

        $delivered = $this->delivered();
        $this->assertSame(self::DIACRITICS, $delivered['Subject']);
        $this->assertStringContainsString(self::DIACRITICS, $delivered['HTML']);
        $this->assertTopLevelContentType('text/html; charset=UTF-8');
    }

    public function testPlainTextHeaderlessMailIsDeliveredAsUtf8(): void
    {
        $this->assertTrue(wp_mail('to@example.org', self::DIACRITICS, self::DIACRITICS));

        $delivered = $this->delivered();
        $this->assertSame(self::DIACRITICS, $delivered['Subject']);
        $this->assertSame(self::DIACRITICS, trim($delivered['Text']));
        $this->assertTopLevelContentType('text/plain; charset=UTF-8');
    }

    /**
     * Text in the scripts most likely to expose a charset or header-folding bug.
     *
     * @return array<string,array{0:string}>
     */
    public static function scripts(): array
    {
        return [
            'bengali'  => ['আমি বাংলায় গান গাই জয় বাংলা'],
            'arabic'   => ['مرحبا بالعالم، هذا اختبار البريد'],
            'hindi'    => ['नमस्ते दुनिया यह एक परीक्षण है'],
            'urdu'     => ['ہیلو دنیا، یہ ایک آزمائش ہے'],
            'hebrew'   => ['שלום עולם זו בדיקה'],
            'chinese'  => ['你好世界这是一封测试邮件'],
            'japanese' => ['こんにちは世界、これはテストです'],
            'korean'   => ['안녕하세요 세계 테스트 메일입니다'],
            'russian'  => ['Привет мир Это проверка почты'],
            'thai'     => ['สวัสดีชาวโลก นี่คือการทดสอบ'],
            'tamil'    => ['வணக்கம் உலகம் இது ஒரு சோதனை'],
            'emoji'    => ['📧 ✅ 🎉 👨‍👩‍👧‍👦 🇧🇩'],
            'mixed'    => ['আমি Arabic مرحبا 你好 नमस्ते 😀'],
        ];
    }

    /**
     * A long subject must fold on character boundaries, and names and body must arrive intact, in every script.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('scripts')]
    public function testScriptSurvivesSubjectBodyAndNames(string $text): void
    {
        $subject = trim(str_repeat($text . ' ', 12));
        $this->addFilter('wp_mail_content_type', static fn (): string => self::BIT_FORM_CONTENT_TYPE);

        $this->assertTrue(wp_mail("{$text} <to@example.org>", $subject, "<p>{$text}</p>", ["Reply-To: {$text} <reply@example.org>"]));

        $delivered = $this->delivered();
        $this->assertSame($subject, $delivered['Subject']);
        $this->assertStringContainsString("<p>{$text}</p>", $delivered['HTML']);
        $this->assertSame($text, $delivered['To'][0]['Name']);
        $this->assertSame($text, $delivered['ReplyTo'][0]['Name']);
    }

    /**
     * wp_mail() arguments holding one bad address, with the recipient that should still get the mail.
     *
     * @return array<string,array{0:string,1:array<int,string>,2:string}>
     */
    public static function oneBadAddress(): array
    {
        return [
            'good and invalid To' => ['Good <good@example.org>, not-an-email', [], 'good@example.org'],
            'comma in To name'    => ['Doe, John <john@example.org>', [], 'john@example.org'],
            'invalid Cc'          => ['good@example.org', ['Cc: not-an-email'], 'good@example.org'],
            'invalid Bcc'         => ['good@example.org', ['Bcc: not-an-email'], 'good@example.org'],
            'invalid Reply-To'    => ['good@example.org', ['Reply-To: not-an-email'], 'good@example.org'],
        ];
    }

    /**
     * One bad address is skipped as core does, instead of losing the mail for everyone else.
     *
     * @param array<int,string> $headers
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('oneBadAddress')]
    public function testBadAddressIsSkippedAndMailStillDelivered(string $to, array $headers, string $recipient): void
    {
        $this->assertTrue(wp_mail($to, 'Subject', 'Body', $headers));

        $this->assertSame([$recipient], array_column($this->delivered()['To'], 'Address'));
    }

    public function testMailWithOnlyInvalidRecipientsFailsLikeCore(): void
    {
        $this->assertFalse(wp_mail('not-an-email', 'Subject', 'Body'));
    }

    public function testUppercaseHeaderIsDeliveredAsHtml(): void
    {
        $this->assertTrue(wp_mail('to@example.org', 'Upper', '<p>' . self::DIACRITICS . '</p>', 'Content-Type: TEXT/HTML; CHARSET=UTF-8'));

        $this->assertStringContainsString(self::DIACRITICS, $this->delivered()['HTML']);
        $this->assertTopLevelContentType('text/html; charset=UTF-8');
    }

    public function testCharsetFilterControlsEncodingOfSubjectAndBody(): void
    {
        $this->addFilter('wp_mail_charset', static fn (): string => 'ISO-8859-2');

        $latin2 = (string) mb_convert_encoding(self::DIACRITICS, 'ISO-8859-2', 'UTF-8');
        $this->assertTrue(wp_mail('to@example.org', $latin2, $latin2));

        $delivered = $this->delivered();
        $this->assertSame(self::DIACRITICS, $delivered['Subject']);
        $this->assertSame(self::DIACRITICS, trim($delivered['Text']));
        $this->assertTopLevelContentType('text/plain; charset=ISO-8859-2');
    }

    /**
     * @return array<string,array{0:array<int,string>|string}>
     */
    public static function multipartHeaders(): array
    {
        return [
            'single line'    => ['Content-Type: multipart/alternative; boundary="b-1"'],
            'with charset'   => ['Content-Type: multipart/alternative; charset=UTF-8; boundary="b-1"'],
            'charset last'   => [['Content-Type: multipart/alternative; boundary="b-1"; charset=UTF-8']],
            'continuation'   => ["Content-Type: multipart/alternative;\n boundary=\"b-1\""],
        ];
    }

    /**
     * @param array<int,string>|string $headers
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('multipartHeaders')]
    public function testMultipartStaysMultipart($headers): void
    {
        $this->assertTrue(wp_mail('to@example.org', self::DIACRITICS, self::MULTIPART_BODY, $headers));

        $delivered = $this->delivered();
        $this->assertSame(self::DIACRITICS, $delivered['Subject']);
        $this->assertSame(self::DIACRITICS . ' plain', trim($delivered['Text']));
        $this->assertStringContainsString(self::DIACRITICS . ' html', $delivered['HTML']);
        $this->assertMatchesRegularExpression('/^Content-Type: multipart\/alternative; boundary="b-1"/m', $this->rawHeaders());
    }

    public function testMultipartWithAttachmentKeepsItsPartsInsidePhpMailerEnvelope(): void
    {
        $attachment = (string) tempnam(sys_get_temp_dir(), 'bit-smtp-attach-');
        file_put_contents($attachment, 'attached');

        try {
            $this->assertTrue(wp_mail('to@example.org', 'With attachment', self::MULTIPART_BODY, 'Content-Type: multipart/alternative; boundary="b-1"', [$attachment]));
        } finally {
            unlink($attachment);
        }

        $delivered = $this->delivered();
        $this->assertSame(self::DIACRITICS . ' plain', trim($delivered['Text']));
        $this->assertStringContainsString(self::DIACRITICS . ' html', $delivered['HTML']);
        $this->assertMatchesRegularExpression('/^Content-Type: multipart\/mixed;/m', $this->rawHeaders());
    }

    public function testCalendarContentTypeSurvives(): void
    {
        $this->assertTrue(wp_mail('to@example.org', 'Invite', "BEGIN:VCALENDAR\r\nEND:VCALENDAR", 'Content-Type: text/calendar; method=REQUEST; charset=UTF-8'));

        $this->delivered();
        $this->assertTopLevelContentType('text/calendar; method=REQUEST; charset=UTF-8');
    }

    public function testInjectionThroughTheContentTypeFilterAddsNoHeaderOrRecipient(): void
    {
        $this->addFilter('wp_mail_content_type', static fn (): string => "text/html\r\nBcc: attacker@evil.test\r\nX-Evil: yes");

        $this->assertTrue(wp_mail('to@example.org', 'Injection', 'Body'));

        $this->assertInjectionHadNoEffect();
        $this->assertTopLevelContentType('text/plain; charset=UTF-8');
    }

    public function testInjectionThroughBoundaryAddsNoHeaderOrRecipient(): void
    {
        $this->addFilter('wp_mail_content_type', static fn (): string => "multipart/mixed; boundary=\"x\r\nBcc: attacker@evil.test\r\nX-Evil: yes\"");

        $this->assertTrue(wp_mail('to@example.org', 'Injection', 'Body'));

        $this->assertInjectionHadNoEffect();
        $this->assertTopLevelContentType('text/plain; charset=UTF-8');
    }

    public function testInjectionThroughTheCharsetFilterAddsNoHeaderOrRecipient(): void
    {
        $this->addFilter('wp_mail_charset', static fn (): string => "UTF-8\r\nBcc: attacker@evil.test\r\nX-Evil: yes");

        $this->assertTrue(wp_mail('to@example.org', 'Injection', 'Body'));

        $this->assertInjectionHadNoEffect();
        $this->assertTopLevelContentType('text/plain; charset=UTF-8');
    }

    public function testPhpmailerInitHookCanStillOverrideCharsetAndContentType(): void
    {
        $this->addFilter('phpmailer_init', static function (PHPMailer $mailer): void {
            $mailer->CharSet     = 'ISO-8859-2';
            $mailer->ContentType = 'text/x-custom';
        });

        $this->assertTrue(wp_mail('to@example.org', 'Override', 'Body'));

        $this->delivered();
        $this->assertTopLevelContentType('text/x-custom; charset=ISO-8859-2');
    }

    public function testAltBodySetInPhpmailerInitProducesMultipartAlternative(): void
    {
        $this->addFilter('phpmailer_init', static function (PHPMailer $mailer): void {
            $mailer->AltBody = self::DIACRITICS . ' alt';
        });

        $this->assertTrue(wp_mail('to@example.org', 'Alt', '<p>' . self::DIACRITICS . ' html</p>', 'Content-Type: text/html; charset=UTF-8'));

        $delivered = $this->delivered();
        $this->assertSame(self::DIACRITICS . ' alt', trim($delivered['Text']));
        $this->assertStringContainsString(self::DIACRITICS . ' html', $delivered['HTML']);
        $this->assertMatchesRegularExpression('/^Content-Type: multipart\/alternative;/m', $this->rawHeaders());
    }

    public function testWooCommerceMultipartPatternDeliversRealAlternativeOverSmtp(): void
    {
        $this->addFilter('wp_mail_content_type', static fn (): string => 'multipart/alternative');
        $this->addFilter('phpmailer_init', static function (PHPMailer $mailer): void {
            $mailer->AltBody = self::DIACRITICS . ' plain';
        });

        $this->assertTrue(wp_mail('to@example.org', 'Order', '<p>' . self::DIACRITICS . ' html</p>', "Content-Type: multipart/alternative\r\n"));

        $delivered = $this->delivered();
        $this->assertSame(self::DIACRITICS . ' plain', trim($delivered['Text']));
        $this->assertStringContainsString(self::DIACRITICS . ' html', $delivered['HTML']);
        $this->assertMatchesRegularExpression('/^Content-Type: multipart\/alternative;/m', $this->rawHeaders());
    }

    public function testWooCommerceMultipartWithoutAltBodyDeliversHtml(): void
    {
        $this->addFilter('wp_mail_content_type', static fn (): string => 'multipart/alternative');

        $this->assertTrue(wp_mail('to@example.org', 'Order', '<p>' . self::DIACRITICS . '</p>', "Content-Type: multipart/alternative\r\n"));

        $this->assertStringContainsString(self::DIACRITICS, $this->delivered()['HTML']);
        $this->assertTopLevelContentType('text/html; charset=UTF-8');
    }

    public function testBareMultipartWithPlainBodyDeliversPlain(): void
    {
        $this->assertTrue(wp_mail('to@example.org', 'Plain', self::DIACRITICS, 'Content-Type: multipart/alternative'));

        $this->assertSame(self::DIACRITICS, trim($this->delivered()['Text']));
        $this->assertTopLevelContentType('text/plain; charset=UTF-8');
    }

    public function testFilterDroppingTheBoundaryKeepsMultipartIntact(): void
    {
        $this->addFilter('wp_mail_content_type', static fn (): string => 'multipart/alternative');

        $this->assertTrue(wp_mail('to@example.org', 'Kept', self::MULTIPART_BODY, 'Content-Type: multipart/alternative; boundary="b-1"'));

        $this->assertSame(self::DIACRITICS . ' plain', trim($this->delivered()['Text']));
        $this->assertMatchesRegularExpression('/^Content-Type: multipart\/alternative; boundary="b-1"/m', $this->rawHeaders());
    }

    public function testBoundaryWithTrailingApostropheStaysMultipart(): void
    {
        $body = str_replace('--b-1', "--abc'", self::MULTIPART_BODY);

        $this->assertTrue(wp_mail('to@example.org', 'Apostrophe', $body, "Content-Type: multipart/alternative; boundary=\"abc'\""));

        $this->assertSame(self::DIACRITICS . ' plain', trim($this->delivered()['Text']));
    }

    #[\PHPUnit\Framework\Attributes\IgnoreDeprecations]
    public function testNonStringHeaderElementDoesNotBreakTheSend(): void
    {
        $this->assertTrue(wp_mail('to@example.org', 'Null header', 'Body', ['From: x@example.org', null]));

        $this->assertSame('Null header', $this->delivered()['Subject']);
    }

    public function testBoundaryOutsideRfc2046StaysMultipart(): void
    {
        $body = str_replace('--b-1', '--{abc}#1', self::MULTIPART_BODY);

        $this->assertTrue(wp_mail('to@example.org', 'Odd boundary', $body, 'Content-Type: multipart/alternative; boundary="{abc}#1"'));

        $this->assertSame(self::DIACRITICS . ' plain', trim($this->delivered()['Text']));
        $this->assertMatchesRegularExpression('/^Content-Type: multipart\/alternative; boundary="\{abc\}#1"/m', $this->rawHeaders());
    }

    public function testRetryRowStoredWithoutCharsetIsDeliveredAsUtf8Html(): void
    {
        $message = MailMessage::fromArray([
            'to'          => ['to@example.org'],
            'subject'     => self::DIACRITICS,
            'body'        => '<p>' . self::DIACRITICS . '</p>',
            'contentType' => self::BIT_FORM_CONTENT_TYPE,
        ]);

        $result = (new SmtpTransport(new DatabaseCredentialResolver()))->send($message, $this->mailpitSmtpConnection());

        $this->assertTrue($result->isOk(), (string) $result->getError());

        $delivered = $this->delivered();
        $this->assertSame(self::DIACRITICS, $delivered['Subject']);
        $this->assertStringContainsString(self::DIACRITICS, $delivered['HTML']);
        $this->assertTopLevelContentType('text/html; charset=UTF-8');
    }

    /**
     * Add a filter that tearDown removes again.
     */
    private function addFilter(string $hook, callable $callback): void
    {
        add_filter($hook, $callback);
        $this->filters[] = [$hook, $callback];
    }

    /**
     * Fetch the latest delivered message, failing the test when the box is empty.
     *
     * @return array<string,mixed>
     */
    private function delivered(): array
    {
        $delivered = $this->latestMailpitMessage();
        $this->assertNotNull($delivered, 'no message reached Mailpit');

        return $delivered;
    }

    /**
     * Raw header block of the latest delivered message, exactly as it went over SMTP.
     */
    private function rawHeaders(): string
    {
        $response = wp_remote_get(self::MAILPIT_API . '/message/' . $this->delivered()['ID'] . '/raw');

        return (string) preg_split('/\r?\n\r?\n/', wp_remote_retrieve_body($response), 2)[0];
    }

    /**
     * Assert the exact top-level Content-Type line of the raw delivered message.
     */
    private function assertTopLevelContentType(string $expected): void
    {
        $this->assertMatchesRegularExpression('/^Content-Type: ' . preg_quote($expected, '/') . '\r?$/m', $this->rawHeaders());
    }

    /**
     * Assert the delivered message gained no recipient or header from an injection attempt.
     */
    private function assertInjectionHadNoEffect(): void
    {
        $delivered = $this->delivered();
        $headers   = $this->rawHeaders();

        $this->assertSame([], $delivered['Bcc']);
        $this->assertCount(1, $delivered['To']);
        $this->assertStringNotContainsString('attacker@evil.test', $headers);
        $this->assertStringNotContainsString('X-Evil', $headers);
    }

    /**
     * Store a Mailpit SMTP connection and refresh the cached service so wp_mail routes through the bridge.
     */
    private function configureMailpitTransport(): void
    {
        $this->storeOptions([
            'status'             => true,
            'smtp_host'          => self::SMTP_HOST,
            'port'               => self::SMTP_PORT,
            'encryption'         => 'none',
            'smtp_auth'          => false,
            'from_email_address' => 'from@example.org',
            'from_name'          => 'From',
        ]);

        Plugin::instance()->mailConfigService()->reload();
    }
}
