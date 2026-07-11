import { DeleteOutlined } from '@ant-design/icons'
import { __ } from '@common/helpers/i18nwrap'
import {
  type EditableRoutingCondition,
  type RoutingField,
  type RoutingOperator
} from '@pages/Routing/types'
import { Button, Flex, Input, Select } from 'antd'

const FIELD_OPTIONS: { value: RoutingField; label: string }[] = [
  { value: 'recipient', label: __('Recipient') },
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

export default function ConditionEditor({
  condition,
  onChange,
  onRemove
}: {
  condition: EditableRoutingCondition
  onChange: (condition: EditableRoutingCondition) => void
  onRemove: () => void
}) {
  return (
    <Flex gap="small" align="center">
      <Select
        aria-label={__('Field')}
        value={condition.field}
        options={FIELD_OPTIONS}
        style={{ width: 160 }}
        onChange={(field: RoutingField) => onChange({ ...condition, field })}
      />
      <Select
        aria-label={__('Operator')}
        value={condition.operator}
        options={OPERATOR_OPTIONS}
        style={{ width: 140 }}
        onChange={(operator: RoutingOperator) => onChange({ ...condition, operator })}
      />
      <Input
        aria-label={__('Value')}
        value={condition.value}
        placeholder={__('Value')}
        onChange={event => onChange({ ...condition, value: event.target.value })}
      />
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
