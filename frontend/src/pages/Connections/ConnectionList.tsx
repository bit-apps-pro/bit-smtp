import { DeleteOutlined } from '@ant-design/icons'
import { __ } from '@common/helpers/i18nwrap'
import { type Connection } from '@pages/Connections/types'
import { Button, Card, Flex, List, Tag, Typography, theme } from 'antd'

const { Text } = Typography

export default function ConnectionList({
  connections,
  defaultId,
  selectedId,
  onSelect,
  onDelete
}: {
  connections: Connection[]
  defaultId: string
  selectedId: string | null
  onSelect: (id: string) => void
  onDelete: (id: string) => void
}) {
  const { token } = theme.useToken()

  return (
    <List
      dataSource={connections}
      renderItem={connection => (
        <List.Item style={{ padding: 0, marginBottom: token.marginSM, border: 'none' }}>
          <Card
            size="small"
            style={{
              width: '100%',
              borderColor: connection.id === selectedId ? token.colorPrimary : undefined
            }}
          >
            <Flex justify="space-between" align="center" gap="small">
              <Flex vertical>
                <Button
                  type="link"
                  style={{ padding: 0, height: 'auto' }}
                  onClick={() => onSelect(connection.id)}
                >
                  {connection.name}
                </Button>
                <Text type="secondary">{connection.provider}</Text>
              </Flex>
              <Flex align="center" gap="small">
                {connection.id === defaultId && <Tag color="blue">{__('Default')}</Tag>}
                <Button
                  danger
                  type="text"
                  icon={<DeleteOutlined />}
                  aria-label={`${__('Delete')} ${connection.name}`}
                  onClick={() => onDelete(connection.id)}
                />
              </Flex>
            </Flex>
          </Card>
        </List.Item>
      )}
    />
  )
}
