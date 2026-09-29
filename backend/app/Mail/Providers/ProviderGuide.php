<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Providers;

use InvalidArgumentException;

/**
 * Builds the setup-guide array each provider publishes (picker group, "best for", doc links, prerequisites).
 *
 * @phpstan-type GuideStep array{id:string,title:string,body:string,docUrl?:string}
 * @phpstan-type Guide array{group:string,bestFor:string,docUrl?:string,fromEmailHint?:string,testCaveat?:string,prerequisites:non-empty-list<GuideStep>}
 */
final class ProviderGuide
{
    public const GROUP_MAILBOX = 'mailbox';

    public const GROUP_TRANSACTIONAL = 'transactional';

    public const GROUP_SERVER = 'server';

    public const GROUPS = [self::GROUP_MAILBOX, self::GROUP_TRANSACTIONAL, self::GROUP_SERVER];

    /**
     * Guide in its canonical key order; optional entries are omitted when null.
     *
     * @param list<GuideStep> $prerequisites
     *
     * @return Guide
     */
    public static function make(
        string $group,
        string $bestFor,
        array $prerequisites,
        ?string $docUrl = null,
        ?string $fromEmailHint = null,
        ?string $testCaveat = null
    ): array {
        if (!\in_array($group, self::GROUPS, true)) {
            throw new InvalidArgumentException(esc_html("Unknown provider guide group: {$group}"));
        }

        if ($prerequisites === []) {
            throw new InvalidArgumentException('A provider guide needs at least one prerequisite.');
        }

        $optional = array_filter(
            ['docUrl' => $docUrl, 'fromEmailHint' => $fromEmailHint, 'testCaveat' => $testCaveat],
            static fn (?string $value): bool => $value !== null
        );

        return ['group' => $group, 'bestFor' => $bestFor] + $optional + ['prerequisites' => $prerequisites];
    }

    /**
     * One prerequisite entry; docUrl is omitted when not given.
     *
     * @return GuideStep
     */
    public static function step(string $id, string $title, string $body, ?string $docUrl = null): array
    {
        $step = ['id' => $id, 'title' => $title, 'body' => $body];

        if ($docUrl !== null) {
            $step['docUrl'] = $docUrl;
        }

        return $step;
    }
}
