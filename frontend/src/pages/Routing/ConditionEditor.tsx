import { useId } from 'react'
import { DeleteOutlined } from '@ant-design/icons'
import { __ } from '@common/helpers/i18nwrap'
import { type HelpDetails, HelpPopover, IconButton } from '@components/FieldHelp'
import {
  type EditableRoutingCondition,
  type MailSource,
  type RoutingField,
  type RoutingOperator
} from '@pages/Routing/types'
import { AutoComplete, Flex, Input, Select, Typography } from 'antd'

const { Text } = Typography

const FIELD_OPTIONS: { value: RoutingField; label: string }[] = [
  { value: 'recipient', label: __('To recipient') },
  { value: 'from', label: __('From') },
  { value: 'subject', label: __('Subject') },
  { value: 'source_plugin', label: __('Source plugin') }
]

const OPERATOR_OPTIONS: { value: RoutingOperator; label: string }[] = [
  { value: 'equals', label: __('is exactly') },
  { value: 'contains', label: __('contains') },
  { value: 'domain', label: __('domain is') },
  { value: 'matches', label: __('matches pattern (regex)') }
]

const DEFAULT_OPERATOR: RoutingOperator = 'equals'

/** Maps fields to their valid operators; domain only matches email-bearing fields (to/from), never subject/plugin. */
const OPERATORS_BY_FIELD: Record<RoutingField, RoutingOperator[]> = {
  recipient: ['equals', 'contains', 'domain', 'matches'],
  from: ['equals', 'contains', 'domain', 'matches'],
  subject: ['equals', 'contains', 'matches'],
  source_plugin: ['equals', 'contains', 'matches']
}

type PlaceholderKind = 'email' | 'subject' | 'source_plugin'

const PLACEHOLDER_KIND: Record<RoutingField, PlaceholderKind> = {
  recipient: 'email',
  from: 'email',
  subject: 'subject',
  source_plugin: 'source_plugin'
}

const PLACEHOLDERS: Record<RoutingOperator, Record<PlaceholderKind, string>> = {
  equals: {
    email: 'customer@example.com',
    subject: __('Your order has shipped'),
    source_plugin: 'woocommerce'
  },
  contains: { email: 'order', subject: __('invoice'), source_plugin: 'woo' },
  domain: { email: 'example.com', subject: 'example.com', source_plugin: 'example.com' },
  matches: { email: '^noreply@.*\\.shop$', subject: '^Order #\\d+', source_plugin: '^woo' }
}

const REGEX_HELP: HelpDetails = {
  title: __('Pattern (regex)'),
  body: __(
    'The rule matches when this pattern appears anywhere in the field. Upper and lower case are treated the same. Use ^ for "starts with" and $ for "ends with".'
  ),
  example: __('^noreply@ matches any address that starts with noreply@.')
}

/** Example value for the chosen field and operator, shown as the input placeholder. */
function valuePlaceholder(field: RoutingField, operator: RoutingOperator): string {
  return PLACEHOLDERS[operator][PLACEHOLDER_KIND[field]]
}

/** Best-effort client-side check that a pattern compiles; empty patterns are left to the empty-value flow. */
function isValidPattern(pattern: string): boolean {
  if (pattern === '') return true
  try {
    RegExp(pattern, 'i')
    return true
  } catch {
    return false
  }
}

/** Case-insensitive match on both the plugin slug and its friendly label. */
function matchesSource(input: string, option?: { value?: string; label?: unknown }): boolean {
  const needle = input.toLowerCase()
  return (
    String(option?.value ?? '')
      .toLowerCase()
      .includes(needle) ||
    String(option?.label ?? '')
      .toLowerCase()
      .includes(needle)
  )
}

/** Source options labelled "Friendly name (slug)" so the stored slug stays visible. */
function sourceOptions(sources: MailSource[]): MailSource[] {
  return sources.map(source => ({
    value: source.value,
    label: source.label === source.value ? source.label : `${source.label} (${source.value})`
  }))
}

/** One match condition row: field, operator, and value inputs, plus remove. */
export default function ConditionEditor({
  condition,
  sources,
  onChange,
  onRemove
}: {
  condition: EditableRoutingCondition
  sources: MailSource[]
  onChange: (condition: EditableRoutingCondition) => void
  onRemove: () => void
}) {
  const patternWarningId = useId()

  /** Resets value on a source_plugin mode switch (stale slug/free-text can't leak) and operator when the new field disallows it. */
  const changeField = (field: RoutingField) => {
    const modeChanged = (field === 'source_plugin') !== (condition.field === 'source_plugin')
    const operator = OPERATORS_BY_FIELD[field].includes(condition.operator)
      ? condition.operator
      : DEFAULT_OPERATOR
    onChange({ ...condition, field, operator, value: modeChanged ? '' : condition.value })
  }

  const operatorOptions = OPERATOR_OPTIONS.filter(option =>
    OPERATORS_BY_FIELD[condition.field].includes(option.value)
  )
  const isPattern = condition.operator === 'matches'
  const hasInvalidPattern = isPattern && !isValidPattern(condition.value)
  const placeholder = valuePlaceholder(condition.field, condition.operator)
  const valueStatus = hasInvalidPattern ? 'warning' : undefined
  const valueDescribedBy = hasInvalidPattern ? patternWarningId : undefined

  return (
    <Flex vertical gap={4}>
      <Flex gap="small" align="center" wrap>
        <Select
          aria-label={__('Field')}
          value={condition.field}
          options={FIELD_OPTIONS}
          style={{ flex: '1 1 150px', minWidth: 0 }}
          onChange={changeField}
        />
        <Select
          aria-label={__('Operator')}
          value={condition.operator}
          options={operatorOptions}
          style={{ flex: '1 1 190px', minWidth: 0 }}
          onChange={(operator: RoutingOperator) => onChange({ ...condition, operator })}
        />
        <Flex align="center" gap={2} style={{ flex: '2 1 220px', minWidth: 0 }}>
          {condition.field === 'source_plugin' ? (
            <AutoComplete
              aria-label={__('Value')}
              aria-describedby={valueDescribedBy}
              value={condition.value}
              options={sourceOptions(sources)}
              placeholder={placeholder}
              status={valueStatus}
              style={{ flex: 1, minWidth: 0 }}
              filterOption={matchesSource}
              onChange={(value: string) => onChange({ ...condition, value })}
            />
          ) : (
            <Input
              aria-label={__('Value')}
              aria-describedby={valueDescribedBy}
              value={condition.value}
              placeholder={placeholder}
              status={valueStatus}
              onChange={event => onChange({ ...condition, value: event.target.value })}
            />
          )}
          {isPattern && <HelpPopover label={__('Help: pattern matching')} details={REGEX_HELP} />}
        </Flex>
        <IconButton
          type="text"
          danger
          icon={<DeleteOutlined />}
          label={__('Remove condition')}
          onClick={onRemove}
        />
      </Flex>
      {hasInvalidPattern && (
        <Text id={patternWarningId} type="warning">
          {__('This pattern has a mistake, so it will never match. Check its brackets and symbols.')}
        </Text>
      )}
    </Flex>
  )
}
