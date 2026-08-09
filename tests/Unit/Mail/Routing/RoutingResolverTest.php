<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Routing;

use BitApps\SMTP\Mail\Routing\RoutingContext;
use BitApps\SMTP\Mail\Routing\RoutingResolver;
use BitApps\SMTP\Mail\Routing\RoutingRules;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class RoutingResolverTest extends BaseUnitTestCase
{
    private RoutingResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new RoutingResolver();
    }

    public function testResolveReturnsFirstMatchingRuleConnectionId(): void
    {
        $rules = RoutingRules::fromArray([
            [
                'conditions'   => [['field' => 'source_plugin', 'operator' => 'equals', 'value' => 'edd']],
                'connectionId' => 'conn-not-matching',
            ],
            [
                'conditions'   => [['field' => 'source_plugin', 'operator' => 'equals', 'value' => 'woocommerce']],
                'connectionId' => 'conn-first-match',
            ],
            [
                'conditions'   => [['field' => 'from', 'operator' => 'equals', 'value' => 'billing@example.com']],
                'connectionId' => 'conn-second-match',
            ],
        ]);

        $result = $this->resolver->resolve($this->context(), $rules);

        $this->assertSame('conn-first-match', $result);
    }

    public function testDecideCapturesTheFirstMatchingRuleAndSource(): void
    {
        $rules = RoutingRules::fromArray([
            [
                'conditions'   => [['field' => 'source_plugin', 'operator' => 'equals', 'value' => 'edd']],
                'connectionId' => 'conn-not-matching',
            ],
            [
                'conditions'   => [['field' => 'source_plugin', 'operator' => 'equals', 'value' => 'woocommerce']],
                'connectionId' => 'conn-first-match',
            ],
            [
                'conditions'   => [['field' => 'from', 'operator' => 'equals', 'value' => 'billing@example.com']],
                'connectionId' => 'conn-second-match',
            ],
        ]);

        $decision = $this->resolver->decide($this->context(), $rules);

        $this->assertSame('woocommerce', $decision->sourcePlugin());
        $this->assertSame('conn-first-match', $decision->connectionId());
        $this->assertSame('rule', $decision->type());
        $this->assertSame(1, $decision->ruleIndex());
        $this->assertSame('fallback', $decision->withType('fallback')->type());
        $this->assertSame('rule', $decision->type());
    }

    public function testDecideReturnsTheDefaultShapeWhenNoRuleMatches(): void
    {
        $rules = RoutingRules::fromArray([
            [
                'conditions'   => [['field' => 'source_plugin', 'operator' => 'equals', 'value' => 'edd']],
                'connectionId' => 'conn-1',
            ],
        ]);

        $decision = $this->resolver->decide($this->context(), $rules);

        $this->assertSame('woocommerce', $decision->sourcePlugin());
        $this->assertNull($decision->connectionId());
        $this->assertSame('default', $decision->type());
        $this->assertNull($decision->ruleIndex());
    }

    public function testResolveReturnsNullWhenNoRuleMatches(): void
    {
        $rules = RoutingRules::fromArray([
            [
                'conditions'   => [['field' => 'source_plugin', 'operator' => 'equals', 'value' => 'edd']],
                'connectionId' => 'conn-1',
            ],
        ]);

        $result = $this->resolver->resolve($this->context(), $rules);

        $this->assertNull($result);
    }

    public function testResolveReturnsNullWhenNoRulesExist(): void
    {
        $rules = RoutingRules::fromArray([]);

        $result = $this->resolver->resolve($this->context(), $rules);

        $this->assertNull($result);
    }

    private function context(): RoutingContext
    {
        return RoutingContext::fromArray([
            'recipients'   => ['jane@example.com'],
            'from'         => 'billing@example.com',
            'subject'      => 'Your Invoice is Ready',
            'sourcePlugin' => 'woocommerce',
        ]);
    }
}
