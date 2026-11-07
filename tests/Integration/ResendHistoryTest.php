<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\HTTP\Controllers\LogController;
use BitApps\SMTP\HTTP\Controllers\SMTPController;
use BitApps\SMTP\HTTP\Requests\ResendEditRequest;
use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Plugin;
use BitApps\SMTP\Settings\PluginSettings;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Verifies resend preserves original log and creates child row for resend history visibility.
 *
 * @internal
 *
 * @coversNothing
 */
final class ResendHistoryTest extends IntegrationTestCase
{
    private const MULTIPART_HEADER = 'Content-Type: multipart/alternative; boundary="b-1"';

    private const MULTIPART_BODY = "--b-1\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nplain part\r\n--b-1\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<p>html part</p>\r\n--b-1--";

    private LogService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateTables((new Log())->getTable());
        $this->service = new LogService();
        $this->useRealPhpMailer();
        $this->configureMailpitTransport();
        wp_set_current_user(1);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function testResendPreservesOriginalAndInsertsLinkedChildRow(): void
    {
        $originalId = $this->seedOriginalLog();

        $this->resend([$originalId]);

        $this->assertNotEmpty($this->mailpitMessages(), 'the resend should have delivered through mailpit');
        $this->assertSame(2, Log::query()->count(), 'the resend must add a row, not overwrite the original');

        $child = Log::where('resend_parent_id', $originalId)->first();
        $this->assertInstanceOf(Log::class, $child);
        $this->assertSame($originalId, (int) $child->resend_parent_id);
        $this->assertNotSame($originalId, (int) $child->id);
        $this->assertSame(Log::SUCCESS, (int) $child->status);

        $original = Log::where('id', $originalId)->first();
        $this->assertSame(0, (int) $original->retry_count, 'the original must not be updated in place');
        $this->assertNull($original->resend_parent_id, 'the original is a parent, never a child');
    }

    public function testDetailsExposeTheResendChainOnBothEnds(): void
    {
        $originalId = $this->seedOriginalLog();
        $this->resend([$originalId]);
        $childId = (int) Log::where('resend_parent_id', $originalId)->first()->id;

        $original = $this->details($originalId);
        $this->assertNull($original['resend_of']);
        $this->assertCount(1, $original['resends']);
        $this->assertSame($childId, $original['resends'][0]['id']);
        $this->assertSame('sent', $original['resends'][0]['status']);
        $this->assertNotSame('', $original['resends'][0]['created_at']);

        $child = $this->details($childId);
        $this->assertSame($originalId, $child['resend_of']);
        $this->assertSame([], $child['resends']);
    }

    /**
     * A non-full body-storage mode drops/redacts the stored body; the endpoint must skip resend.
     */
    public function testResendSkipsALogWhoseBodyWasNotRetained(): void
    {
        PluginSettings::make()->set('log_store_body', 'metadata')->save();
        $originalId = $this->seedOriginalLog();

        $this->resend([$originalId]);

        $this->assertEmpty($this->mailpitMessages(), 'a body-less log must not be resent');
        $this->assertSame(1, Log::query()->count(), 'no child row may be created for a skipped resend');
        $this->assertEmpty(Log::where('resend_parent_id', $originalId)->first(), 'no resend child should exist');
    }

    public function testResendLeavesAMultipartMessageMultipart(): void
    {
        $this->resend([$this->seedLog(self::MULTIPART_BODY, self::MULTIPART_HEADER)]);

        $this->assertDeliveredAsMultipart();
    }

    public function testResendKeepsPlainTextPlainWithItsLineBreaks(): void
    {
        $this->resend([$this->seedLog("Line one\nLine two", '')]);

        $this->assertStringContainsString('text/plain', $this->deliveredContentType());
        $this->assertStringContainsString("Line one\nLine two", str_replace("\r\n", "\n", $this->latestMailpitMessage()['Text']));
    }

    public function testResendTreatsAnAddressInAngleBracketsAsPlainText(): void
    {
        $this->resend([$this->seedLog('Reply to John <john@example.org> or <https://example.org/x>', '')]);

        $this->assertStringContainsString('text/plain', $this->deliveredContentType());
    }

    public function testResendSendsATaggedBodyWithoutAHeaderAsHtml(): void
    {
        $this->resend([$this->seedLog('<p>Hello <b>there</b></p>', '')]);

        $this->assertStringContainsString('text/html', $this->deliveredContentType());
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function taggedBodiesDeclaredPlain(): array
    {
        return [
            'live link'    => ['Message: <a href="http://evil.example">click</a>'],
            'placeholder'  => ["Dear <name>,\nline two"],
        ];
    }

    #[DataProvider('taggedBodiesDeclaredPlain')]
    public function testResendHonoursAnExplicitPlainTextHeader(string $body): void
    {
        $this->resend([$this->seedLog($body, 'Content-Type: text/plain; charset=UTF-8')]);

        $this->assertStringContainsString('text/plain', $this->deliveredContentType());
        $this->assertSame(str_replace("\r\n", "\n", $body), str_replace("\r\n", "\n", trim($this->latestMailpitMessage()['Text'])));
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function bodiesThatOnlyLookLikeTags(): array
    {
        return [
            'comparison'    => ['if a<b and c>d'],
            'across lines'  => ["a <b\nc=\"d\"> e"],
            'unquoted math' => ['x <y z> w'],
        ];
    }

    #[DataProvider('bodiesThatOnlyLookLikeTags')]
    public function testResendDoesNotPromoteTextThatOnlyLooksLikeTags(string $body): void
    {
        $this->resend([$this->seedLog($body, '')]);

        $this->assertStringContainsString('text/plain', $this->deliveredContentType());
    }

    public function testResendKeepsMultipartWhenAnotherFilterSetsIt(): void
    {
        $filter = static fn (): string => 'multipart/alternative; boundary="b-1"';
        add_filter('wp_mail_content_type', $filter, 5);

        try {
            $this->resend([$this->seedLog(self::MULTIPART_BODY, '')]);
        } finally {
            remove_filter('wp_mail_content_type', $filter, 5);
        }

        $this->assertDeliveredAsMultipart();
    }

    public function testNativeResendLeavesABareMultipartFromAFilterAlone(): void
    {
        $this->storeOptions([]);
        Plugin::instance()->mailConfigService()->reload();

        $preset = static fn (): string => 'multipart/alternative';
        $seen   = null;
        $spy    = static function (PHPMailer $mailer) use (&$seen): void {
            $seen = $mailer->ContentType;
        };
        add_filter('wp_mail_content_type', $preset, 5);
        add_action('phpmailer_init', $spy);

        try {
            $this->resend([$this->seedLog('<p>Hello</p>', '')]);
        } finally {
            remove_filter('wp_mail_content_type', $preset, 5);
            remove_action('phpmailer_init', $spy);
        }

        $this->assertSame('multipart/alternative', $seen);
    }

    public function testEditedResendHonoursAnExplicitPlainTextHeader(): void
    {
        $this->resendEdit($this->seedLog('Message: <a href="http://evil.example">click</a>', 'Content-Type: text/plain'));

        $this->assertStringContainsString('text/plain', $this->deliveredContentType());
    }

    public function testEditedResendIsSentAsUtf8WhateverCharsetWasLogged(): void
    {
        $this->resendEdit($this->seedLog('<p>Hello</p>', 'Content-Type: text/html; charset=ISO-8859-1'), 'Café');

        $this->assertStringContainsString('charset=UTF-8', $this->deliveredContentType());
        $this->assertSame('Café', $this->latestMailpitMessage()['Subject']);
    }

    public function testResendDecidesTheContentTypePerMessage(): void
    {
        $plain = $this->seedLog('Plain first', '', 'Plain row');
        $html  = $this->seedLog('<p>Html second</p>', '', 'Html row');

        $this->resend([$plain, $html]);

        $types = [];
        foreach ($this->mailpitMessages() as $summary) {
            $types[$summary['Subject']] = (string) ($this->mailpitHeaders($summary['ID'])['Content-Type'][0] ?? '');
        }

        $this->assertStringContainsString('text/plain', $types['Plain row']);
        $this->assertStringContainsString('text/html', $types['Html row']);
    }

    public function testResendRemovesItsFilterEvenWhenTheSendThrows(): void
    {
        $id      = $this->seedLog('<p>Body</p>', '');
        $thrower = static function (): void {
            throw new RuntimeException('send blew up');
        };
        add_filter('pre_wp_mail', $thrower, 1);

        try {
            $this->resend([$id]);
            $this->fail('the exception should propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('send blew up', $e->getMessage());
        } finally {
            remove_filter('pre_wp_mail', $thrower, 1);
        }

        $this->assertFalse(has_filter('wp_mail_content_type'));
    }

    public function testEditedResendLeavesAMultipartMessageMultipart(): void
    {
        $this->resendEdit($this->seedLog(self::MULTIPART_BODY, self::MULTIPART_HEADER));

        $this->assertDeliveredAsMultipart();
    }

    public function testEditedResendKeepsPlainTextPlain(): void
    {
        $this->resendEdit($this->seedLog("Line one\nLine two", ''));

        $this->assertStringContainsString('text/plain', $this->deliveredContentType());
    }

    public function testEditedResendSendsATaggedBodyAsHtml(): void
    {
        $this->resendEdit($this->seedLog('<p>Hello</p>', ''));

        $this->assertStringContainsString('text/html', $this->deliveredContentType());
    }

    /**
     * Seed a retained log row with the given body and headers, returning its id.
     *
     * @param array<int,string>|string $headers
     */
    private function seedLog(string $body, $headers, string $subject = 'Seeded send'): int
    {
        $this->service->save(Log::SUCCESS, [
            'subject'     => $subject,
            'to'          => ['to@example.org'],
            'message'     => $body,
            'headers'     => $headers,
            'attachments' => [],
        ]);

        return (int) Log::where('subject', $subject)->first()->id;
    }

    /**
     * Run the edited resend endpoint for a log row over the configured connection.
     */
    private function resendEdit(int $id, string $subject = 'Edited'): void
    {
        $connection = Plugin::instance()->mailConfigService()->load()->getConnections()->first();
        $request    = new ResendEditRequest();
        $request->make([
            'id'            => $id,
            'to'            => ['edited@example.org'],
            'subject'       => $subject,
            'connection_id' => $connection->getId(),
        ], $request->rules());

        (new SMTPController())->resendEdit($request);
    }

    /**
     * Content-Type header of the latest delivered message.
     */
    private function deliveredContentType(): string
    {
        $delivered = $this->latestMailpitMessage();
        $this->assertNotNull($delivered, 'nothing reached Mailpit');

        return (string) ($this->mailpitHeaders($delivered['ID'])['Content-Type'][0] ?? '');
    }

    /**
     * Assert the latest delivered message kept its multipart type and both parts.
     */
    private function assertDeliveredAsMultipart(): void
    {
        $this->assertStringContainsString('multipart/alternative', $this->deliveredContentType());

        $delivered = $this->latestMailpitMessage();
        $this->assertSame('plain part', trim($delivered['Text']));
        $this->assertStringContainsString('html part', $delivered['HTML']);
    }

    private function seedOriginalLog(): int
    {
        $this->service->save(Log::SUCCESS, [
            'subject'     => 'Original send',
            'to'          => ['to@example.org'],
            'message'     => 'Original body',
            'headers'     => '',
            'attachments' => [],
        ]);

        return (int) Log::where('subject', 'Original send')->first()->id;
    }

    /**
     * @param array<int,int> $ids
     */
    private function resend(array $ids): void
    {
        $request      = new Request();
        $request->ids = $ids;
        (new SMTPController())->resend($request);
    }

    /**
     * @return array<string,mixed>
     */
    private function details(int $id): array
    {
        $request     = new Request();
        $request->id = $id;
        (new LogController())->details($request);

        return (array) Response::getData();
    }

    /**
     * Store SMTP config and refresh the cached service to apply new options.
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
