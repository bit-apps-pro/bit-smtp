import { DeleteOutlined } from '@ant-design/icons'
import { __ } from '@common/helpers/i18nwrap'
import { Button, Card, Flex, Form, Input, Popconfirm, Switch, Tag, Typography, theme } from 'antd'
import { Send } from 'lucide-react'
import { type ChannelDefinition } from './NotificationChannelFields'

const { Text } = Typography

const NAME_MAX_LENGTH = 60

interface NotificationChannelCardProps {
  index: number
  definition: ChannelDefinition
  onRemove: () => void
  onTest: () => void
  testLoading: boolean
  testDisabled: boolean
}

/** One added notification channel: name/type/on-off header, its fields, and Send test/Remove actions. */
export default function NotificationChannelCard({
  index,
  definition,
  onRemove,
  onTest,
  testLoading,
  testDisabled
}: NotificationChannelCardProps) {
  const { token } = theme.useToken()
  const form = Form.useFormInstance()
  const name: string | undefined = Form.useWatch(['channels', index, 'name'], form)
  const { Fields, icon: Icon } = definition
  const displayName = name?.trim() || definition.label

  return (
    <Card
      size="small"
      title={
        <Flex align="center" gap={8}>
          <Icon size={16} color={token.colorTextSecondary} strokeWidth={1.75} aria-hidden="true" />
          <Text strong ellipsis>
            {displayName}
          </Text>
          {!displayName.startsWith(definition.label) && <Tag bordered={false}>{definition.label}</Tag>}
        </Flex>
      }
      extra={
        <Form.Item name={[index, 'enabled']} valuePropName="checked" noStyle>
          <Switch size="small" aria-label={__('Channel enabled')} />
        </Form.Item>
      }
      actions={[
        <Button
          key="test"
          type="text"
          icon={<Send size={14} />}
          onClick={onTest}
          loading={testLoading}
          disabled={testDisabled}
        >
          {__('Send test')}
        </Button>,
        <Popconfirm
          key="remove"
          title={__('Remove this channel?')}
          onConfirm={onRemove}
          okText={__('Remove')}
          cancelText={__('Cancel')}
        >
          <Button type="text" danger icon={<DeleteOutlined />}>
            {__('Remove')}
          </Button>
        </Popconfirm>
      ]}
      styles={{ body: { paddingBlock: token.padding } }}
    >
      <Form.Item name={[index, 'id']} hidden>
        <Input />
      </Form.Item>
      <Form.Item name={[index, 'type']} hidden>
        <Input />
      </Form.Item>
      <Flex vertical gap="middle">
        <Form.Item name={[index, 'name']} label={__('Name')} style={{ marginBottom: 0 }}>
          <Input maxLength={NAME_MAX_LENGTH} placeholder={definition.label} />
        </Form.Item>
        <Fields index={index} />
      </Flex>
    </Card>
  )
}
