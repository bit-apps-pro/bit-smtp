import { Fragment } from 'react'
import { DeleteOutlined, HolderOutlined, PlusOutlined } from '@ant-design/icons'
import { __, sprintf } from '@common/helpers/i18nwrap'
import { IconButton } from '@components/FieldHelp'
import { type DraggableAttributes, type DraggableSyntheticListeners } from '@dnd-kit/core'
import ProviderLogo from '@pages/Connections/ProviderLogo'
import { getProviderVisual } from '@pages/Connections/providerVisuals'
import { type Connection } from '@pages/Connections/types'
import {
  type EditableRoutingCondition,
  type EditableRoutingRule,
  type MailSourceCatalog
} from '@pages/Routing/types'
import { Alert, Button, Card, Flex, Popconfirm, Select, Typography, theme } from 'antd'
import ConditionEditor from './ConditionEditor'

const { Text } = Typography

const PROVIDER_BADGE_SIZE = 24

let nextConditionId = 0
/** Client-side-only id for a newly added condition row. */
function createConditionId(): string {
  nextConditionId += 1
  return `condition-${nextConditionId}`
}

/** Blank condition row seeded with sensible defaults. */
function emptyCondition(): EditableRoutingCondition {
  return { id: createConditionId(), field: 'recipient', operator: 'equals', value: '' }
}

/** One routing rule: target-connection picker plus its match conditions, with a drag handle and delete. */
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
  sources: MailSourceCatalog
  priority?: number
  onChange: (rule: EditableRoutingRule) => void
  onRemove: () => void
  dragHandleAttributes?: DraggableAttributes
  dragHandleListeners?: DraggableSyntheticListeners
}) {
  const { token } = theme.useToken()
  const connectionOptions = connections.map(connection => ({
    value: connection.id,
    label: connection.enabled ? connection.name : sprintf(__('%s (disabled)'), connection.name)
  }))
  const ruleLabel = typeof priority === 'number' ? sprintf(__('Rule %d'), priority) : __('Rule')
  const targetConnection = connections.find(connection => connection.id === rule.connectionId)

  /** Replaces the condition at `index`. */
  const updateCondition = (index: number, condition: EditableRoutingCondition) => {
    const conditions = rule.conditions.map((item, itemIndex) => (itemIndex === index ? condition : item))
    onChange({ ...rule, conditions })
  }

  /** Drops the condition at `index`. */
  const removeCondition = (index: number) => {
    onChange({ ...rule, conditions: rule.conditions.filter((_, itemIndex) => itemIndex !== index) })
  }

  /** Appends a blank condition. */
  const addCondition = () => {
    onChange({ ...rule, conditions: [...rule.conditions, emptyCondition()] })
  }

  return (
    <Card
      size="small"
      title={
        <Flex align="center" gap="small">
          <IconButton
            type="text"
            size="small"
            icon={<HolderOutlined />}
            label={__('Drag to reorder')}
            style={{ cursor: 'grab', color: token.colorTextSecondary }}
            // eslint-disable-next-line react/jsx-props-no-spreading -- dnd-kit's own a11y attributes/listeners
            {...dragHandleAttributes}
            // eslint-disable-next-line react/jsx-props-no-spreading -- dnd-kit's own a11y attributes/listeners
            {...dragHandleListeners}
          />
          <Text strong>{ruleLabel}</Text>
        </Flex>
      }
      extra={
        <Popconfirm
          title={sprintf(__('Delete %s?'), ruleLabel)}
          description={__(
            'Matching emails will use the next rule or your primary connection. Click Save changes to apply.'
          )}
          onConfirm={onRemove}
          okText={__('Delete rule')}
          okButtonProps={{ danger: true }}
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
            {targetConnection && (
              <ProviderLogo
                visual={getProviderVisual(targetConnection.provider)}
                size={PROVIDER_BADGE_SIZE}
              />
            )}
            <Select
              aria-label={__('Target connection')}
              placeholder={__('Select a connection')}
              value={rule.connectionId || undefined}
              options={connectionOptions}
              style={{ width: '100%', maxWidth: 320 }}
              onChange={(connectionId: string) => onChange({ ...rule, connectionId })}
            />
          </Flex>
        </Flex>
        <Flex vertical gap="small">
          {rule.conditions.length > 0 ? (
            <Text type="secondary" style={{ fontSize: token.fontSizeSM }}>
              {__('Send it here when all of these conditions match')}
            </Text>
          ) : (
            <Alert
              type="warning"
              showIcon
              message={__('This rule has no conditions, so it never runs. Add a condition.')}
            />
          )}
          {rule.conditions.map((condition, index) => (
            <Fragment key={condition.id}>
              {index > 0 && (
                <Text type="secondary" strong style={{ fontSize: token.fontSizeSM }}>
                  {__('and')}
                </Text>
              )}
              <ConditionEditor
                condition={condition}
                sources={sources}
                onChange={updated => updateCondition(index, updated)}
                onRemove={() => removeCondition(index)}
              />
            </Fragment>
          ))}
          <Button type="dashed" icon={<PlusOutlined />} onClick={addCondition}>
            {__('Add condition')}
          </Button>
        </Flex>
      </Flex>
    </Card>
  )
}
