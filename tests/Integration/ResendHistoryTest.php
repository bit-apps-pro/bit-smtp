<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\HTTP\Controllers\LogController;
use BitApps\SMTP\HTTP\Controllers\SMTPController;
use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Plugin;
use BitApps\SMTP\Settings\PluginSettings;

/**
 * Drives SMTPController::resend end-to-end against mailpit: a manual resend must preserve the
 * original log row and INSERT a new child row linked to it, so the resend is visible as history
 * rather than overwriting the log it was launched from.
 *
 * @internal
 *
 * @coversNothing
 */
final class ResendHistoryTest extends IntegrationTestCase
{
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

    public function testResendSkipsALogWhoseBodyWasNotRetained(): void
    {
        // Under a non-full body-storage mode the stored body is dropped/redacted, so there is
        // nothing to resend; the endpoint must skip it rather than mail an empty/placeholder body.
        PluginSettings::make()->set('log_store_body', 'metadata')->save();
        $originalId = $this->seedOriginalLog();

        $this->resend([$originalId]);

        $this->assertEmpty($this->mailpitMessages(), 'a body-less log must not be resent');
        $this->assertSame(1, Log::query()->count(), 'no child row may be created for a skipped resend');
        $this->assertEmpty(Log::where('resend_parent_id', $originalId)->first(), 'no resend child should exist');
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

        // The config service caches on first load; refresh it so this test's options take effect.
        Plugin::instance()->mailConfigService()->reload();
    }
}
