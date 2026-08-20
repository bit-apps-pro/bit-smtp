import { DeleteOutlined, HolderOutlined, PlusOutlined } from '@ant-design/icons'
import { __ } from '@common/helpers/i18nwrap'
import { type DraggableAttributes, type DraggableSyntheticListeners } from '@dnd-kit/core'
import { type ProviderVisual, getProviderVisual } from '@pages/Connections/providerVisuals'
import { type Connection } from '@pages/Connections/types'
import {
  type EditableRoutingCondition,
  type EditableRoutingRule,
  type MailSource
} from '@pages/Routing/types'
import { Button, Card, Flex, Popconfirm, Select, Typography, theme } from 'antd'
import ConditionEditor from './ConditionEditor'

const { Text } = Typography

const PROVIDER_BADGE_SIZE = 24

let nextConditionId = 0
function createConditionId(): string {
  nextConditionId += 1
  return `condition-${nextConditionId}`
}

function emptyCondition(): EditableRoutingCondition {
  return { id: createConditionId(), field: 'recipient', operator: 'equals', value: '' }
}

/** Mirrors ConnectionCard's logo-or-initial badge so a rule's target reads as the same provider identity. */
function ProviderBadge({ visual }: { visual: ProviderVisual }) {
  const { token } = theme.useToken()

  if (visual.logo) {
    return (
      <img
        src={visual.logo}
        alt=""
        width={PROVIDER_BADGE_SIZE}
        height={PROVIDER_BADGE_SIZE}
        style={{ objectFit: 'contain', flexShrink: 0 }}
      />
    )
  }

  return (
    <Flex
      align="center"
      justify="center"
      aria-hidden="true"
      style={{
        width: PROVIDER_BADGE_SIZE,
        height: PROVIDER_BADGE_SIZE,
        flexShrink: 0,
        borderRadius: token.borderRadius,
        backgroundColor: visual.accent,
        color: token.colorWhite,
        fontSize: token.fontSizeSM,
        fontWeight: token.fontWeightStrong
      }}
    >
      {visual.initial}
    </Flex>
  )
}

export default function RoutingRuleRow({
  rule,
  connections,
  sources,
  priority,
  onChange,
  onRemove,
  dragHandleAttributes,
  dragHandleListeners
}: {
  rule: EditableRoutingRule
  connections: Connection[]
  sources: MailSource[]
  priority?: number
  onChange: (rule: EditableRoutingRule) => void
  onRemove: () => void
  dragHandleAttributes?: DraggableAttributes
  dragHandleListeners?: DraggableSyntheticListeners
}) {
  const { token } = theme.useToken()
  const connectionOptions = connections.map(connection => ({
    value: connection.id,
    label: connection.name
  }))
  const targetConnection = connections.find(connection => connection.id === rule.connectionId)

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
      title={
        <Flex align="center" gap="small">
          <Button
            type="text"
            size="small"
            icon={<HolderOutlined />}
            aria-label={__('Drag to reorder')}
            style={{ cursor: 'grab' }}
            // eslint-disable-next-line react/jsx-props-no-spreading -- dnd-kit's own a11y attributes/listeners
            {...dragHandleAttributes}
            // eslint-disable-next-line react/jsx-props-no-spreading -- dnd-kit's own a11y attributes/listeners
            {...dragHandleListeners}
          />
          <Text strong>{typeof priority === 'number' ? `${__('Rule')} ${priority}` : __('Rule')}</Text>
        </Flex>
      }
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
      <Flex vertical gap="middle">
        <Flex vertical gap={4}>
          <Text type="secondary" style={{ fontSize: token.fontSizeSM }}>
            {__('Route matching mail to')}
          </Text>
          <Flex align="center" gap="small">
            {targetConnection && <ProviderBadge visual={getProviderVisual(targetConnection.provider)} />}
            <Select
              aria-label={__('Target connection')}
              placeholder={__('Select a connection')}
              value={rule.connectionId || undefined}
              options={connectionOptions}
              style={{ width: 260 }}
              onChange={(connectionId: string) => onChange({ ...rule, connectionId })}
            />
          </Flex>
        </Flex>
        <Flex vertical gap="small">
          {rule.conditions.map((condition, index) => (
            <ConditionEditor
              key={condition.id}
              condition={condition}
              sources={sources}
              onChange={updated => updateCondition(index, updated)}
              onRemove={() => removeCondition(index)}
            />
          ))}
          <Button type="dashed" icon={<PlusOutlined />} onClick={addCondition}>
            {__('Add condition')}
          </Button>
        </Flex>
      </Flex>
    </Card>
  )
}
