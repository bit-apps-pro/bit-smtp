import { type CSSProperties } from 'react'
import {
  DeleteOutlined,
  EditOutlined,
  HolderOutlined,
  MailOutlined,
  StarOutlined
} from '@ant-design/icons'
import { __, sprintf } from '@common/helpers/i18nwrap'
import { IconButton } from '@components/FieldHelp'
import { type DraggableAttributes, type DraggableSyntheticListeners } from '@dnd-kit/core'
import { getProviderVisual } from '@pages/Connections/providerVisuals'
import { type Connection, type ConnectionHealth } from '@pages/Connections/types'
import { Button, Card, Flex, Popconfirm, Switch, Tag, Tooltip, Typography, theme } from 'antd'
import HealthBadge from './HealthBadge'
import ProviderLogo from './ProviderLogo'

const { Text, Title } = Typography

const PROVIDER_BADGE_SIZE = 32

/** What else a delete changes: routing rules that target the connection go too, and a primary is replaced. */
function deleteConsequence(isDefault: boolean, routingRuleCount: number): string {
  const parts = [
    routingRuleCount === 1 ? __('The routing rule that uses it is deleted too.') : null,
    routingRuleCount > 1
      ? sprintf(__('The %d routing rules that use it are deleted too.'), routingRuleCount)
      : null,
    isDefault ? __('Another enabled connection becomes the primary.') : null
  ].filter((part): part is string => part !== null)

  return parts.length > 0 ? parts.join(' ') : __("This can't be undone.")
}

/** Renders connection identity in the header and status controls in the body. */
export default function ConnectionCard({
  connection,
  isDefault,
  backupNumber,
  routingRuleCount = 0,
  health,
  onSetDefault,
  onToggleEnabled,
  onEdit,
  onDelete,
  isToggling,
  dragHandleAttributes,
  dragHandleListeners
}: {
  connection: Connection
  isDefault: boolean
  backupNumber?: number
  routingRuleCount?: number
  health?: ConnectionHealth
  onSetDefault: () => void
  onToggleEnabled: (enabled: boolean) => void
  onEdit: () => void
  onDelete: () => void
  isToggling?: boolean
  dragHandleAttributes?: DraggableAttributes
  dragHandleListeners?: DraggableSyntheticListeners
}) {
  const { token } = theme.useToken()
  const visual = getProviderVisual(connection.provider)
  const isEnabled = connection.enabled
  const toggleLabel = isEnabled ? __('Disable this connection') : __('Enable this connection')
  const nameId = `connection-card-name-${connection.id}`
  const isDraggable = !isDefault && dragHandleListeners !== undefined

  let cardStyle: CSSProperties | undefined
  if (isDefault) {
    cardStyle = {
      borderInlineStart: `4px solid ${token.colorPrimary}`,
      backgroundColor: token.colorPrimaryBg
    }
  } else if (!isEnabled) {
    cardStyle = { opacity: 0.6 }
  }

  return (
    <Card
      style={cardStyle}
      styles={{ title: { overflow: 'visible' } }}
      title={
        <Flex align="center" gap={12} style={{ width: '100%' }}>
          {isDraggable && (
            <IconButton
              type="text"
              size="small"
              icon={<HolderOutlined />}
              label={__('Drag to reorder')}
              style={{ cursor: 'grab', flexShrink: 0, color: token.colorTextSecondary }}
              // eslint-disable-next-line react/jsx-props-no-spreading -- dnd-kit's own a11y attributes/listeners
              {...dragHandleAttributes}
              // eslint-disable-next-line react/jsx-props-no-spreading -- dnd-kit's own a11y attributes/listeners
              {...dragHandleListeners}
            />
          )}
          <ProviderLogo visual={visual} size={PROVIDER_BADGE_SIZE} />
          <Title
            id={nameId}
            level={5}
            style={{
              margin: 0,
              lineHeight: 1.35,
              flex: 1,
              minWidth: 0,
              whiteSpace: 'normal',
              overflowWrap: 'anywhere'
            }}
          >
            {connection.name}
          </Title>
        </Flex>
      }
      actions={[
        <Flex
          key="actions"
          role="group"
          aria-label={__('Connection actions')}
          align="center"
          justify="space-around"
          gap={4}
          wrap
          style={{ paddingInline: token.paddingXS }}
        >
          {!isDefault && (
            <Tooltip title={isEnabled ? undefined : __('Turn this connection on first')}>
              <span tabIndex={isEnabled ? undefined : 0}>
                <Button type="text" icon={<StarOutlined />} disabled={!isEnabled} onClick={onSetDefault}>
                  {__('Set as primary')}
                </Button>
              </span>
            </Tooltip>
          )}
          <Button type="text" icon={<EditOutlined />} onClick={onEdit}>
            {__('Edit')}
          </Button>
          <Popconfirm
            title={sprintf(__('Delete "%s"?'), connection.name)}
            description={deleteConsequence(isDefault, routingRuleCount)}
            onConfirm={onDelete}
            okText={__('Delete connection')}
            okButtonProps={{ danger: true }}
            cancelText={__('Cancel')}
          >
            <Button type="text" danger icon={<DeleteOutlined />}>
              {__('Delete')}
            </Button>
          </Popconfirm>
        </Flex>
      ]}
    >
      <Flex vertical gap={12}>
        <Flex role="group" aria-labelledby={nameId} align="center" gap={8} wrap>
          <Tooltip title={toggleLabel}>
            <Switch
              size="small"
              checked={isEnabled}
              loading={isToggling}
              onChange={onToggleEnabled}
              aria-label={toggleLabel}
            />
          </Tooltip>
          {health && <HealthBadge health={health} connectionName={connection.name} />}
          {isDefault && (
            <Tag bordered={false} color={token.colorPrimary}>
              {__('Primary')}
            </Tag>
          )}
          {!isEnabled && <Tag bordered={false}>{__('Disabled')}</Tag>}
          {!isDefault && typeof backupNumber === 'number' && (
            <Tag bordered={false}>{sprintf(__('Backup #%d'), backupNumber)}</Tag>
          )}
        </Flex>
        <Text type="secondary" style={{ fontSize: token.fontSizeSM }}>
          {visual.blurb}
        </Text>
        <Flex align="center" gap={6}>
          <MailOutlined style={{ color: token.colorTextTertiary }} />
          <Text type="secondary">{connection.fromEmail}</Text>
        </Flex>
      </Flex>
    </Card>
  )
}
