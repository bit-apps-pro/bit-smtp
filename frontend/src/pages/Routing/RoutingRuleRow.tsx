import { DeleteOutlined, PlusOutlined } from '@ant-design/icons'
import { __ } from '@common/helpers/i18nwrap'
import { type Connection } from '@pages/Connections/types'
import { type EditableRoutingCondition, type EditableRoutingRule } from '@pages/Routing/types'
import { Button, Card, Flex, Popconfirm, Select } from 'antd'
import ConditionEditor from './ConditionEditor'

let nextConditionId = 0
function createConditionId(): string {
  nextConditionId += 1
  return `condition-${nextConditionId}`
}

function emptyCondition(): EditableRoutingCondition {
  return { id: createConditionId(), field: 'recipient', operator: 'equals', value: '' }
}

export default function RoutingRuleRow({
  rule,
  connections,
  onChange,
  onRemove
}: {
  rule: EditableRoutingRule
  connections: Connection[]
  onChange: (rule: EditableRoutingRule) => void
  onRemove: () => void
}) {
  const connectionOptions = connections.map(connection => ({
    value: connection.id,
    label: connection.name
  }))

  const updateCondition = (index: number, condition: EditableRoutingCondition) => {
    const conditions = rule.conditions.map((item, itemIndex) => (itemIndex === index ? condition : item))
    onChange({ ...rule, conditions })
  }

  const removeCondition = (index: number) => {
    onChange({ ...rule, conditions: rule.conditions.filter((_, itemIndex) => itemIndex !== index) })
  }

  const addCondition = () => {
    onChange({ ...rule, conditions: [...rule.conditions, emptyCondition()] })
  }

  return (
    <Card
      size="small"
      extra={
        <Popconfirm
          title={__('Delete this rule?')}
          onConfirm={onRemove}
          okText={__('OK')}
          cancelText={__('Cancel')}
        >
          <Button type="text" danger icon={<DeleteOutlined />}>
            {__('Delete rule')}
          </Button>
        </Popconfirm>
      }
    >
      <Flex vertical gap="small">
        <Select
          aria-label={__('Target connection')}
          placeholder={__('Select a connection')}
          value={rule.connectionId || undefined}
          options={connectionOptions}
          style={{ width: 260 }}
          onChange={(connectionId: string) => onChange({ ...rule, connectionId })}
        />
        {rule.conditions.map((condition, index) => (
          <ConditionEditor
            key={condition.id}
            condition={condition}
            onChange={updated => updateCondition(index, updated)}
            onRemove={() => removeCondition(index)}
          />
        ))}
        <Button type="dashed" icon={<PlusOutlined />} onClick={addCondition}>
          {__('Add condition')}
        </Button>
      </Flex>
    </Card>
  )
}
