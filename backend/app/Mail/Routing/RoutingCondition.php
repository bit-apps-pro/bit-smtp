<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Routing;

final class RoutingCondition
{
    private const FIELD_RECIPIENT = 'recipient';

    private const FIELD_FROM = 'from';

    private string $field;

    private string $operator;

    private string $value;

    private function __construct(string $field, string $operator, string $value)
    {
        $this->field    = $field;
        $this->operator = $operator;
        $this->value    = $value;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['field'] ?? ''),
            (string) ($data['operator'] ?? ''),
            (string) ($data['value'] ?? '')
        );
    }

    public function toArray(): array
    {
        return [
            'field'    => $this->field,
            'operator' => $this->operator,
            'value'    => $this->value,
        ];
    }

    public function getField(): string
    {
        return $this->field;
    }

    public function getOperator(): string
    {
        return $this->operator;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function matches(RoutingContext $context): bool
    {
        switch ($this->operator) {
            case 'equals':
                return $this->anyFieldValue($context, function (string $fieldValue): bool {
                    return strcasecmp($fieldValue, $this->value) === 0;
                });

            case 'contains':
                return $this->anyFieldValue($context, function (string $fieldValue): bool {
                    return stripos($fieldValue, $this->value) !== false;
                });

            case 'domain':
                return $this->matchesDomain($context);

            case 'matches':
                return $this->matchesRegex($context);

            default:
                return false;
        }
    }

    private function matchesDomain(RoutingContext $context): bool
    {
        if ($this->field !== self::FIELD_RECIPIENT && $this->field !== self::FIELD_FROM) {
            return false;
        }

        return $this->anyFieldValue($context, function (string $fieldValue): bool {
            return strcasecmp($this->domainOf($fieldValue), $this->value) === 0;
        });
    }

    private function matchesRegex(RoutingContext $context): bool
    {
        $pattern = '#' . str_replace('#', '\#', $this->value) . '#i';

        return $this->anyFieldValue($context, static function (string $fieldValue) use ($pattern): bool {
            return @preg_match($pattern, $fieldValue) === 1;
        });
    }

    private function domainOf(string $emailLike): string
    {
        $atPosition = strrpos($emailLike, '@');

        if ($atPosition === false) {
            return '';
        }

        return substr($emailLike, $atPosition + 1);
    }

    /**
     * @param callable(string): bool $predicate
     */
    private function anyFieldValue(RoutingContext $context, callable $predicate): bool
    {
        foreach ($this->fieldValues($context) as $fieldValue) {
            if ($predicate($fieldValue)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    private function fieldValues(RoutingContext $context): array
    {
        switch ($this->field) {
            case self::FIELD_RECIPIENT:
                return $context->getRecipients();

            case self::FIELD_FROM:
                return [$context->getFrom()];

            case 'subject':
                return [$context->getSubject()];

            case 'source_plugin':
                return [$context->getSourcePlugin()];

            default:
                return [];
        }
    }
}
