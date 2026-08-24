import { type ReactNode } from 'react'
import { DeleteOutlined } from '@ant-design/icons'
import { __ } from '@common/helpers/i18nwrap'
import { Button, Card, Flex, type FormInstance, Popconfirm, Typography, theme } from 'antd'
import { type ChannelDefinition } from './NotificationChannelFields'
import { type NotificationFormValues } from './NotificationsChannels'

const { Text } = Typography

interface NotificationChannelCardProps {
  channel: ChannelDefinition
  alertsForm: FormInstance<NotificationFormValues>
  disabled: boolean
  onRemove: () => void
  onTest?: () => void
  testDisabled?: boolean
  testLoading?: boolean
}

/** One added notification channel: icon/title header, its Form.Items, and Test/Remove actions — mirrors ConnectionCard. */
export default function NotificationChannelCard({
  channel,
  alertsForm,
  disabled,
  onRemove,
  onTest,
  testDisabled,
  testLoading
}: NotificationChannelCardProps) {
  const { token } = theme.useToken()
  const { Fields, icon: Icon } = channel

  const actions: ReactNode[] = []
  if (channel.testChannel) {
    actions.push(
      <Button key="test" type="text" onClick={onTest} disabled={testDisabled} loading={testLoading}>
        {channel.testLabel}
      </Button>
    )
  }
  actions.push(
    <Popconfirm
      key="remove"
      title={__('Remove this channel?')}
      onConfirm={onRemove}
      okText={__('OK')}
      cancelText={__('Cancel')}
    >
      {/* Removal is a cleanup action, not a configuration edit — always available even with the
          master switch off, so a stale channel (and its stored secret) can still be purged. */}
      <Button type="text" danger icon={<DeleteOutlined />}>
        {__('Remove')}
      </Button>
    </Popconfirm>
  )

  return (
    <Card
      title={
        <Flex align="center" gap={8}>
          <Icon size={16} color={token.colorTextSecondary} strokeWidth={1.75} aria-hidden="true" />
          <Text strong>{channel.label}</Text>
        </Flex>
      }
      actions={actions}
    >
      <Fields alertsForm={alertsForm} disabled={disabled} />
    </Card>
  )
}
