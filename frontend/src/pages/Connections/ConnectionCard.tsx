import { DeleteOutlined, EditOutlined, HolderOutlined, StarOutlined } from '@ant-design/icons'
import { __ } from '@common/helpers/i18nwrap'
import { type DraggableAttributes, type DraggableSyntheticListeners } from '@dnd-kit/core'
import { type Connection } from '@pages/Connections/types'
import { Button, Card, Flex, Popconfirm, Tag, Typography } from 'antd'

const { Text } = Typography

export default function ConnectionCard({
  connection,
  isDefault,
  priority,
  onSetDefault,
  onEdit,
  onDelete,
  dragHandleAttributes,
  dragHandleListeners
}: {
  connection: Connection
  isDefault: boolean
  priority?: number
  onSetDefault: () => void
  onEdit: () => void
  onDelete: () => void
  dragHandleAttributes?: DraggableAttributes
  dragHandleListeners?: DraggableSyntheticListeners
}) {
  return (
    <Card
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
          <Text>{connection.name}</Text>
        </Flex>
      }
      extra={
        <Flex gap="small" align="center">
          {typeof priority === 'number' && <Tag>{`${__('Priority')} ${priority}`}</Tag>}
          {isDefault && <Tag color="blue">{__('Default')}</Tag>}
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
      <Flex vertical gap="small">
        <Tag>{connection.provider}</Tag>
        <Text type="secondary">{connection.fromEmail}</Text>
      </Flex>
    </Card>
  )
}
