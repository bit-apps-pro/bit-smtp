<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Routing;

final class RoutingRule
{
    /**
     * @var RoutingCondition[]
     */
    private array $conditions;

    private string $connectionId;

    private function __construct(array $conditions, string $connectionId)
    {
        $this->conditions   = $conditions;
        $this->connectionId = $connectionId;
    }

    public static function fromArray(array $data): self
    {
        $conditions = array_map(
            static function (array $condition): RoutingCondition {
                return RoutingCondition::fromArray($condition);
            },
            $data['conditions'] ?? []
        );

        return new self(array_values($conditions), (string) ($data['connectionId'] ?? ''));
    }

    public function toArray(): array
    {
        return [
            'conditions' => array_map(
                static function (RoutingCondition $condition): array {
                    return $condition->toArray();
                },
                $this->conditions
            ),
            'connectionId' => $this->connectionId,
        ];
    }

    public function getConnectionId(): string
    {
        return $this->connectionId;
    }

    /**
     * @return RoutingCondition[]
     */
    public function getConditions(): array
    {
        return $this->conditions;
    }

    public function matches(RoutingContext $context): bool
    {
        if ($this->conditions === []) {
            return false;
        }

        foreach ($this->conditions as $condition) {
            if (!$condition->matches($context)) {
                return false;
            }
        }

        return true;
    }
}
