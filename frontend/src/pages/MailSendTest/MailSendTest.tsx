import { useState } from 'react'
import { DownOutlined, UpOutlined } from '@ant-design/icons'
import { __ } from '@common/helpers/i18nwrap'
import DebugOutput from '@components/DebugOutput/DebugOutput'
import config from '@config/config'
import { Button, Card, Flex, Form, Input, Space, theme } from 'antd'
import useTestMailSend from './data/useTestMailSend'

const { TextArea } = Input

export default function MailSendTest() {
  const [form] = Form.useForm()
  const [isMessageExpanded, setIsMessageExpanded] = useState(false)
  const { mutate: sendTestMail, isPending, data: { data: debugInfo } = {} } = useTestMailSend()
  const { token } = theme.useToken()

  const onFinish = (values: Record<string, string>) => {
    sendTestMail(values, {
      onSuccess: res => {
        if (res.code === 'SUCCESS') {
          form.resetFields()
        }
      }
    })
  }

  return (
    <Flex justify="center" style={{ padding: token.paddingLG }}>
      <Card
        title={__('Test Your Mail')}
        style={{ width: '100%', maxWidth: 560 }}
        extra={
          <Button type="primary" onClick={() => form.submit()} loading={isPending}>
            {__('Send Test Email')}
          </Button>
        }
      >
        <Form
          form={form}
          onFinish={onFinish}
          layout="vertical"
          initialValues={{
            to: config.SEND_TO,
            subject: config.SUBJECT
          }}
        >
          <Form.Item
            name="to"
            label={__('To')}
            rules={[
              { required: true, message: 'Please enter recipient email' },
              { type: 'email', message: 'Please enter a valid email' },
              {
                pattern: /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/,
                message: 'Please enter a valid email address'
              },
              {
                max: 254,
                message: 'Email address is too long'
              }
            ]}
          >
            <Input placeholder="Enter Email Address" type="email" />
          </Form.Item>

          <Form.Item
            name="subject"
            label={__('Subject')}
            rules={[{ required: true, message: 'Please enter email subject' }]}
          >
            <Input placeholder="Email Subject" />
          </Form.Item>

          <Space direction="vertical" style={{ width: '100%' }}>
            <Button
              type="link"
              onClick={() => setIsMessageExpanded(!isMessageExpanded)}
              icon={isMessageExpanded ? <UpOutlined /> : <DownOutlined />}
              style={{ padding: 0, height: 'auto', textAlign: 'left' }}
            >
              {isMessageExpanded ? __('Hide Message') : __('Add Message')}
            </Button>

            {isMessageExpanded && (
              <Form.Item
                name="message"
                label={__('Message')}
                rules={[{ message: 'Please enter email message' }]}
              >
                <TextArea
                  placeholder="Write your message"
                  style={{
                    resize: 'both',
                    minHeight: '100px',
                    minWidth: '100%',
                    maxWidth: '100%'
                  }}
                />
              </Form.Item>
            )}
          </Space>
        </Form>
        {debugInfo?.length ? <DebugOutput log={debugInfo} /> : ''}
      </Card>
    </Flex>
  )
}
