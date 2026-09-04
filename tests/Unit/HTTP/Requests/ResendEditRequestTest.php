<?php

namespace BitApps\SMTP\Tests\Unit\HTTP\Requests;

use BitApps\SMTP\HTTP\Requests\ResendEditRequest;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;

/**
 * Covers the edited-resend request's validation boundary: recipient lists are cleaned to valid,
 * de-duplicated addresses (rejecting header-injection payloads), and a sending connection is required.
 *
 * @internal
 *
 * @coversNothing
 */
final class ResendEditRequestTest extends BaseUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The base Request constructor always reads $_GET through wp_unslash(), even when empty.
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('sanitize_email')->returnArg(1);
        // A minimal is_email: a single address with no whitespace, one @, and a dotted domain.
        Functions\when('is_email')->alias(static function ($email) {
            return \is_string($email) && preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email) === 1 ? $email : false;
        });
    }

    public function testRecipientsKeepsValidDedupedEmailsAndDropsInvalidOnes(): void
    {
        $request = new ResendEditRequest();
        $request->make([
            'id'            => 5,
            'subject'       => 'Hi',
            'connection_id' => 'c1',
            'to'            => ['a@example.org', 'a@example.org', ' b@example.org ', 'not-an-email'],
        ], $request->rules());

        $this->assertSame(['a@example.org', 'b@example.org'], $request->recipients('to'));
    }

    public function testRecipientsKeepsTheDisplayNameForm(): void
    {
        $request = new ResendEditRequest();
        $request->make([
            'id'            => 5,
            'subject'       => 'Hi',
            'connection_id' => 'c1',
            'to'            => ['John Doe <john@example.org>', 'plain@example.org'],
        ], $request->rules());

        $this->assertSame(
            ['John Doe <john@example.org>', 'plain@example.org'],
            $request->recipients('to')
        );
    }

    public function testRecipientsRejectsHeaderInjectionPayloads(): void
    {
        $request = new ResendEditRequest();
        $request->make([
            'id'            => 5,
            'subject'       => 'Hi',
            'connection_id' => 'c1',
            'to'            => ["x@example.org\r\nBcc: evil@attacker.test"],
        ], $request->rules());

        $this->assertSame([], $request->recipients('to'));
    }

    public function testConnectionIdIsRequired(): void
    {
        $request = new ResendEditRequest();
        $request->make(['id' => 5, 'subject' => 'Hi', 'to' => ['a@example.org']], $request->rules());

        $this->assertTrue($request->fails());
        $this->assertArrayHasKey('connection_id', $request->errors());
    }
}
