<?php

namespace BitApps\SMTP\Tests\Unit\HTTP\Requests;

use BitApps\SMTP\HTTP\Requests\ResendEditRequest;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;

/**
 * Covers edited-resend request validation: cleans recipients to valid deduplicated addresses, requires connection.
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

        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('sanitize_email')->returnArg(1);

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
