<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Routing;

use BitApps\SMTP\Mail\Routing\RoutingCondition;
use BitApps\SMTP\Mail\Routing\RoutingContext;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class RoutingConditionTest extends BaseUnitTestCase
{
    public function testFromArrayAndToArrayRoundTrip(): void
    {
        $data      = ['field' => 'recipient', 'operator' => 'equals', 'value' => 'jane@example.com'];
        $condition = RoutingCondition::fromArray($data);

        $this->assertSame($data, $condition->toArray());
        $this->assertSame('recipient', $condition->getField());
        $this->assertSame('equals', $condition->getOperator());
        $this->assertSame('jane@example.com', $condition->getValue());
    }

    // --- equals ---

    public function testEqualsMatchesAnyRecipientCaseInsensitively(): void
    {
        $condition = RoutingCondition::fromArray(['field' => 'recipient', 'operator' => 'equals', 'value' => 'JANE@EXAMPLE.COM']);

        $this->assertTrue($condition->matches($this->context()));
    }

    public function testEqualsFailsWhenNoRecipientMatches(): void
    {
        $condition = RoutingCondition::fromArray(['field' => 'recipient', 'operator' => 'equals', 'value' => 'nobody@example.com']);

        $this->assertFalse($condition->matches($this->context()));
    }

    public function testEqualsMatchesFromField(): void
    {
        $condition = RoutingCondition::fromArray(['field' => 'from', 'operator' => 'equals', 'value' => 'BILLING@example.com']);

        $this->assertTrue($condition->matches($this->context()));
    }

    public function testEqualsMatchesSubjectField(): void
    {
        $condition = RoutingCondition::fromArray(['field' => 'subject', 'operator' => 'equals', 'value' => 'your invoice is ready']);

        $this->assertTrue($condition->matches($this->context()));
    }

    public function testEqualsMatchesSourcePluginField(): void
    {
        $condition = RoutingCondition::fromArray(['field' => 'source_plugin', 'operator' => 'equals', 'value' => 'WooCommerce']);

        $this->assertTrue($condition->matches($this->context()));
    }

    public function testEqualsFailsOnSourcePluginMismatch(): void
    {
        $condition = RoutingCondition::fromArray(['field' => 'source_plugin', 'operator' => 'equals', 'value' => 'edd']);

        $this->assertFalse($condition->matches($this->context()));
    }

    // --- contains ---

    public function testContainsMatchesAnyRecipientSubstring(): void
    {
        $condition = RoutingCondition::fromArray(['field' => 'recipient', 'operator' => 'contains', 'value' => 'SALES']);

        $this->assertTrue($condition->matches($this->context()));
    }

    public function testContainsFailsWhenSubstringAbsentFromAllRecipients(): void
    {
        $condition = RoutingCondition::fromArray(['field' => 'recipient', 'operator' => 'contains', 'value' => 'support']);

        $this->assertFalse($condition->matches($this->context()));
    }

    public function testContainsMatchesSubjectSubstring(): void
    {
        $condition = RoutingCondition::fromArray(['field' => 'subject', 'operator' => 'contains', 'value' => 'invoice']);

        $this->assertTrue($condition->matches($this->context()));
    }

    // --- domain ---

    public function testDomainMatchesAnyRecipientDomain(): void
    {
        $condition = RoutingCondition::fromArray(['field' => 'recipient', 'operator' => 'domain', 'value' => 'SALES.example.com']);

        $this->assertTrue($condition->matches($this->context()));
    }

    public function testDomainFailsWhenNoRecipientDomainMatches(): void
    {
        $condition = RoutingCondition::fromArray(['field' => 'recipient', 'operator' => 'domain', 'value' => 'other.com']);

        $this->assertFalse($condition->matches($this->context()));
    }

    public function testDomainMatchesFromDomain(): void
    {
        $condition = RoutingCondition::fromArray(['field' => 'from', 'operator' => 'domain', 'value' => 'example.com']);

        $this->assertTrue($condition->matches($this->context()));
    }

    public function testDomainIsAlwaysFalseForSubject(): void
    {
        $condition = RoutingCondition::fromArray(['field' => 'subject', 'operator' => 'domain', 'value' => 'example.com']);

        $this->assertFalse($condition->matches($this->context()));
    }

    public function testDomainIsAlwaysFalseForSourcePlugin(): void
    {
        $condition = RoutingCondition::fromArray(['field' => 'source_plugin', 'operator' => 'domain', 'value' => 'example.com']);

        $this->assertFalse($condition->matches($this->context()));
    }

    // --- matches (regex) ---

    public function testMatchesOperatorMatchesValidRegexAgainstAnyRecipient(): void
    {
        $condition = RoutingCondition::fromArray(['field' => 'recipient', 'operator' => 'matches', 'value' => '^john@']);

        $this->assertTrue($condition->matches($this->context()));
    }

    public function testMatchesOperatorFailsWhenRegexDoesNotMatchAnyRecipient(): void
    {
        $condition = RoutingCondition::fromArray(['field' => 'recipient', 'operator' => 'matches', 'value' => '^nomatch@']);

        $this->assertFalse($condition->matches($this->context()));
    }

    public function testMatchesOperatorMatchesSubjectRegex(): void
    {
        $condition = RoutingCondition::fromArray(['field' => 'subject', 'operator' => 'matches', 'value' => 'Invoice.*Ready']);

        $this->assertTrue($condition->matches($this->context()));
    }

    public function testMatchesOperatorWithInvalidRegexReturnsFalseWithoutWarning(): void
    {
        $condition = RoutingCondition::fromArray(['field' => 'subject', 'operator' => 'matches', 'value' => '(unterminated']);

        $this->assertFalse($condition->matches($this->context()));
    }

    public function testMatchesOperatorEscapesDelimiterCharacterInValue(): void
    {
        $condition = RoutingCondition::fromArray(['field' => 'from', 'operator' => 'matches', 'value' => 'billing#example']);

        $this->assertTrue($condition->matches($this->context(['from' => 'billing#example.com'])));
    }

    // --- unknown field/operator safety ---

    public function testUnknownOperatorReturnsFalse(): void
    {
        $condition = RoutingCondition::fromArray(['field' => 'from', 'operator' => 'bogus', 'value' => 'example.com']);

        $this->assertFalse($condition->matches($this->context()));
    }

    private function context(array $overrides = []): RoutingContext
    {
        return RoutingContext::fromArray(array_merge([
            'recipients'   => ['jane@example.com', 'john@sales.example.com'],
            'from'         => 'billing@example.com',
            'subject'      => 'Your Invoice is Ready',
            'sourcePlugin' => 'woocommerce',
        ], $overrides));
    }
}
