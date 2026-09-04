import { type CSSProperties } from 'react'
import {
  DeleteOutlined,
  EditOutlined,
  HolderOutlined,
  MailOutlined,
  StarOutlined
} from '@ant-design/icons'
import { __ } from '@common/helpers/i18nwrap'
import { type DraggableAttributes, type DraggableSyntheticListeners } from '@dnd-kit/core'
import { getProviderVisual } from '@pages/Connections/providerVisuals'
import { type Connection, type ConnectionHealth } from '@pages/Connections/types'
import { Button, Card, Flex, Popconfirm, Switch, Tag, Tooltip, Typography, theme } from 'antd'
import HealthBadge from './HealthBadge'

const { Text, Title } = Typography

const PROVIDER_BADGE_SIZE = 32

/** Renders connection identity in the header and status controls in the body. */
export default function ConnectionCard({
  connection,
  isDefault,
  priority,
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
  priority?: number
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

  // The default connection gets a primary accent rail + tint; a disabled (non-default) card is
  // dimmed to signal it is paused and out of the failover chain.
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
          <Button
            type="text"
            size="small"
            icon={<HolderOutlined />}
            aria-label={__('Drag to reorder')}
            style={{ cursor: 'grab', flexShrink: 0 }}
            // eslint-disable-next-line react/jsx-props-no-spreading -- dnd-kit's own a11y attributes/listeners
            {...dragHandleAttributes}
            // eslint-disable-next-line react/jsx-props-no-spreading -- dnd-kit's own a11y attributes/listeners
            {...dragHandleListeners}
          />
          {visual.logo ? (
            <Flex align="center" justify="center" aria-hidden="true" style={{ flexShrink: 0 }}>
              <img
                src={visual.logo}
                alt=""
                width={PROVIDER_BADGE_SIZE}
                height={PROVIDER_BADGE_SIZE}
                style={{ objectFit: 'contain' }}
              />
            </Flex>
          ) : (
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
                fontWeight: token.fontWeightStrong
              }}
            >
              {visual.initial}
            </Flex>
          )}
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
          <Button
            type="text"
            icon={<StarOutlined />}
            disabled={isDefault || !isEnabled}
            onClick={onSetDefault}
          >
            {__('Set default')}
          </Button>
          <Button type="text" icon={<EditOutlined />} onClick={onEdit}>
            {__('Edit')}
          </Button>
          <Popconfirm
            title={__('Delete this connection?')}
            onConfirm={onDelete}
            okText={__('OK')}
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
          {health && <HealthBadge health={health} />}
          {isDefault && (
            <Tag bordered={false} color={token.colorPrimary}>
              {__('Default')}
            </Tag>
          )}
          {!isEnabled && <Tag bordered={false}>{__('Disabled')}</Tag>}
          {typeof priority === 'number' && <Tag bordered={false}>{`${__('Priority')} ${priority}`}</Tag>}
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
