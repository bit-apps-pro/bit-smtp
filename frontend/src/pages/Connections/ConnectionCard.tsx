import { DeleteOutlined, EditOutlined, StarOutlined } from '@ant-design/icons'
import { __ } from '@common/helpers/i18nwrap'
import { type Connection } from '@pages/Connections/types'
import { Button, Card, Flex, Popconfirm, Tag, Typography } from 'antd'

const { Text } = Typography

export default function ConnectionCard({
  connection,
  isDefault,
  onSetDefault,
  onEdit,
  onDelete
}: {
  connection: Connection
  isDefault: boolean
  onSetDefault: () => void
  onEdit: () => void
  onDelete: () => void
}) {
  return (
    <Card
      title={connection.name}
      extra={isDefault && <Tag color="blue">{__('Default')}</Tag>}
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
