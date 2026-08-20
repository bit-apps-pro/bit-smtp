<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Routing;

use ArrayIterator;
use IteratorAggregate;

final class RoutingRules implements IteratorAggregate
{
    /**
     * @var RoutingRule[]
     */
    private array $rules;

    private function __construct(array $rules)
    {
        $this->rules = $rules;
    }

    public static function fromArray(array $rules): self
    {
        return new self(array_values(array_map(
            static function (array $rule): RoutingRule {
                return RoutingRule::fromArray($rule);
            },
            $rules
        )));
    }

    /**
     * @return RoutingRule[]
     */
    public function all(): array
    {
        return $this->rules;
    }

    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->rules);
    }
}
