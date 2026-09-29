import { useState } from 'react'
import { DownOutlined, UpOutlined } from '@ant-design/icons'
import { __ } from '@common/helpers/i18nwrap'
import DebugOutput from '@components/DebugOutput/DebugOutput'
import { HelpfulFormItem } from '@components/FieldHelp'
import { ReviewPrompt, updateOnboardingFlags } from '@components/Onboarding'
import config from '@config/config'
import useMailSettings from '@pages/Connections/data/useMailSettings'
import { type Connection } from '@pages/Connections/types'
import { Button, Card, Flex, Form, Input, Select, theme } from 'antd'
import TestSendResult from './TestSendResult'
import useTestMailSend, { type TestMailValues } from './data/useTestMailSend'

const { TextArea } = Input

const MAX_EMAIL_LENGTH = 254

/** Selector options: routing with backups first, then each connection (turned-off ones disabled). */
function connectionOptions(connections: Connection[]) {
  return [
    { value: '', label: __('Default (with backups)') },
    ...connections.map(connection => ({
      value: connection.id,
      label: connection.enabled
        ? connection.name || connection.provider
        : `${connection.name || connection.provider} ${__('(turned off)')}`,
      disabled: !connection.enabled
    }))
  ]
}

/** Test page: send a test email through routing and backups, or through one chosen connection. */
export default function MailSendTest() {
  const [form] = Form.useForm<TestMailValues>()
  const [isMessageExpanded, setIsMessageExpanded] = useState(false)
  const { mutate: sendTestMail, isPending, data: response } = useTestMailSend()
  const { data: settings, isPending: settingsPending } = useMailSettings()
  const { token } = theme.useToken()
  const succeeded = response?.code === 'SUCCESS'

  const onFinish = (values: TestMailValues) => {
    sendTestMail(values, {
      onSuccess: res => {
        if (res.code === 'SUCCESS') {
          updateOnboardingFlags({ testEmailSent: true })
        }
      }
    })
  }

  return (
    <Flex justify="center" style={{ padding: token.paddingLG }}>
      <Card title={__('Send a test email')} style={{ width: '100%', maxWidth: 560 }}>
        <Form
          form={form}
          onFinish={onFinish}
          layout="vertical"
          initialValues={{ to: config.SEND_TO, subject: config.SUBJECT, connection_id: '' }}
        >
          <HelpfulFormItem<TestMailValues>
            name="connection_id"
            label={__('Send using')}
            hint={__('Default uses your primary connection, then backups if it fails.')}
          >
            <Select options={connectionOptions(settings?.connections ?? [])} loading={settingsPending} />
          </HelpfulFormItem>

          <Form.Item
            name="to"
            label={__('Send to')}
            rules={[
              { required: true, message: __('Enter the email address to send the test to.') },
              { type: 'email', message: __('Enter a valid email address, like name@example.com.') },
              { max: MAX_EMAIL_LENGTH, message: __('This email address is too long.') }
            ]}
          >
            <Input placeholder={__('name@example.com')} type="email" autoComplete="email" />
          </Form.Item>

          <Form.Item
            name="subject"
            label={__('Subject')}
            rules={[{ required: true, message: __('Enter a subject for the test email.') }]}
          >
            <Input placeholder={__('Email subject')} />
          </Form.Item>

          <Button
            type="link"
            onClick={() => setIsMessageExpanded(!isMessageExpanded)}
            icon={isMessageExpanded ? <UpOutlined /> : <DownOutlined />}
            aria-expanded={isMessageExpanded}
            style={{ padding: 0, height: 'auto', marginBottom: token.marginSM }}
          >
            {isMessageExpanded ? __('Hide message') : __('Add a message')}
          </Button>

          {isMessageExpanded && (
            <Form.Item name="message" label={__('Message')}>
              <TextArea
                placeholder={__('Leave empty to send our standard test email.')}
                autoSize={{ minRows: 4 }}
              />
            </Form.Item>
          )}

          <Button type="primary" htmlType="submit" loading={isPending} block>
            {__('Send test email')}
          </Button>
        </Form>

        {response ? (
          <Flex vertical gap="middle" style={{ marginTop: token.marginMD }}>
            <TestSendResult response={response} />
            {succeeded ? <ReviewPrompt /> : null}
            {response.data.debug.length > 0 ? <DebugOutput log={response.data.debug} /> : null}
          </Flex>
        ) : null}
      </Card>
    </Flex>
  )
}
