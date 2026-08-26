import { DeleteOutlined } from '@ant-design/icons'
import { __ } from '@common/helpers/i18nwrap'
import {
  type EditableRoutingCondition,
  type MailSource,
  type RoutingField,
  type RoutingOperator
} from '@pages/Routing/types'
import { AutoComplete, Button, Flex, Input, Select } from 'antd'

const FIELD_OPTIONS: { value: RoutingField; label: string }[] = [
  // Labelled "To recipient" (not "Recipient"): the matcher only sees the To address, never Cc/Bcc.
  { value: 'recipient', label: __('To recipient') },
  { value: 'from', label: __('From') },
  { value: 'subject', label: __('Subject') },
  { value: 'source_plugin', label: __('Source plugin') }
]

const OPERATOR_OPTIONS: { value: RoutingOperator; label: string }[] = [
  { value: 'equals', label: __('Equals') },
  { value: 'contains', label: __('Contains') },
  { value: 'domain', label: __('Domain') },
  { value: 'matches', label: __('Matches') }
]

const DEFAULT_OPERATOR: RoutingOperator = 'equals'

// `domain` compares the host part of an email address, so it is only meaningful for the
// email-bearing fields; subject and source_plugin carry no address to extract a domain from, and a
// domain condition on them silently never matches (RoutingCondition::matchesDomain) — killing the rule.
const OPERATORS_BY_FIELD: Record<RoutingField, RoutingOperator[]> = {
  recipient: ['equals', 'contains', 'domain', 'matches'],
  from: ['equals', 'contains', 'domain', 'matches'],
  subject: ['equals', 'contains', 'matches'],
  source_plugin: ['equals', 'contains', 'matches']
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
  // Switching into or out of source_plugin resets the value so a stale slug or free-text value
  // cannot leak across the two input modes; an operator the new field disallows (e.g. `domain` on
  // subject) is reset to a valid default so the UI cannot build a rule that never matches.
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

  return (
    <Flex gap="small" align="center">
      <Select
        aria-label={__('Field')}
        value={condition.field}
        options={FIELD_OPTIONS}
        style={{ width: 160 }}
        onChange={changeField}
      />
      <Select
        aria-label={__('Operator')}
        value={condition.operator}
        options={operatorOptions}
        style={{ width: 140 }}
        onChange={(operator: RoutingOperator) => onChange({ ...condition, operator })}
      />
      {condition.field === 'source_plugin' ? (
        <AutoComplete
          aria-label={__('Value')}
          value={condition.value}
          options={sources}
          placeholder={__('Value')}
          style={{ flex: 1 }}
          filterOption={matchesSource}
          onChange={(value: string) => onChange({ ...condition, value })}
        />
      ) : (
        <Input
          aria-label={__('Value')}
          value={condition.value}
          placeholder={__('Value')}
          onChange={event => onChange({ ...condition, value: event.target.value })}
        />
      )}
      <Button
        type="text"
        danger
        icon={<DeleteOutlined />}
        aria-label={__('Remove condition')}
        onClick={onRemove}
      />
    </Flex>
  )
}
