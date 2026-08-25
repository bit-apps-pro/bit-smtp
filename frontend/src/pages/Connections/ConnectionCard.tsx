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
import { Button, Card, Flex, Popconfirm, Tag, Typography, theme } from 'antd'
import HealthBadge from './HealthBadge'

const { Text } = Typography

const PROVIDER_BADGE_SIZE = 30

export default function ConnectionCard({
  connection,
  isDefault,
  priority,
  health,
  onSetDefault,
  onEdit,
  onDelete,
  dragHandleAttributes,
  dragHandleListeners
}: {
  connection: Connection
  isDefault: boolean
  priority?: number
  health?: ConnectionHealth
  onSetDefault: () => void
  onEdit: () => void
  onDelete: () => void
  dragHandleAttributes?: DraggableAttributes
  dragHandleListeners?: DraggableSyntheticListeners
}) {
  const { token } = theme.useToken()
  const visual = getProviderVisual(connection.provider)

  return (
    <Card
      style={
        isDefault
          ? {
              borderInlineStart: `4px solid ${token.colorPrimary}`,
              backgroundColor: token.colorPrimaryBg
            }
          : undefined
      }
      styles={{ title: { overflow: 'visible', whiteSpace: 'normal', textOverflow: 'clip' } }}
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
          <Text strong>{connection.name}</Text>
        </Flex>
      }
      extra={
        <Flex gap="small" align="center">
          {health && <HealthBadge health={health} />}
          {typeof priority === 'number' && <Tag bordered={false}>{`${__('Priority')} ${priority}`}</Tag>}
          {isDefault && (
            <Tag bordered={false} color={token.colorPrimary}>
              {__('Default')}
            </Tag>
          )}
        </Flex>
      }
      actions={[
        <Button
          key="default"
          type="text"
          icon={<StarOutlined />}
          disabled={isDefault}
          onClick={onSetDefault}
        >
          {__('Set default')}
        </Button>,
        <Button key="edit" type="text" icon={<EditOutlined />} onClick={onEdit}>
          {__('Edit')}
        </Button>,
        <Popconfirm
          key="delete"
          title={__('Delete this connection?')}
          onConfirm={onDelete}
          okText={__('OK')}
          cancelText={__('Cancel')}
        >
          <Button type="text" danger icon={<DeleteOutlined />}>
            {__('Delete')}
          </Button>
        </Popconfirm>
      ]}
    >
      <Flex vertical gap={4}>
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
