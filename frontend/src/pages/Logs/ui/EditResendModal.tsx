import { __ } from '@common/helpers/i18nwrap'
import useMailSettings from '@pages/Connections/data/useMailSettings'
import { type LogType } from '@pages/Logs/data/useFetchLogs'
import useResendEditLog from '@pages/Logs/data/useResendEditLog'
import { Form, Input, Modal, Select, notification } from 'antd'

interface EditResendModalProps {
  log: LogType
  open: boolean
  onClose: () => void
}

type EditResendForm = {
  connection_id: string
  to: Array<string>
  subject: string
  cc: Array<string>
  bcc: Array<string>
}

/**
 * Detail-page dialog to resend a logged message with edited recipients/subject over a chosen
 * connection. The body is reused server-side (not editable); the connection is the sender selector.
 */
export default function EditResendModal({ log, open, onClose }: EditResendModalProps) {
  const [form] = Form.useForm<EditResendForm>()
  const { data: mailSettings } = useMailSettings()
  const { resendEditLog, isResendingEdit } = useResendEditLog()

  const connectionOptions = (mailSettings?.connections ?? [])
    .filter(connection => connection.enabled)
    .map(connection => ({ value: connection.id, label: connection.name }))

  const initialValues: EditResendForm = {
    connection_id: connectionOptions[0]?.value ?? '',
    to: log.to_addr ?? [],
    subject: log.subject ?? '',
    cc: log.cc ?? [],
    bcc: log.bcc ?? []
  }

  const handleSubmit = async () => {
    try {
      const values = await form.validateFields()
      const res = await resendEditLog({ id: log.id, ...values })
      if (res?.code === 'SUCCESS') {
        notification.success({ message: res.message || __('Mail resent') })
        onClose()
      } else {
        notification.error({ message: res?.message || __('Failed to resend mail') })
      }
    } catch (e) {
      // antd marks required-field errors inline; only notify on an actual send failure.
      if (e && typeof e === 'object' && 'errorFields' in e) return
      notification.error({ message: __('Failed to resend mail') })
    }
  }

  return (
    <Modal
      title={__('Edit & Resend')}
      open={open}
      onOk={handleSubmit}
      okText={__('Send')}
      confirmLoading={isResendingEdit}
      onCancel={onClose}
      destroyOnHidden
    >
      <Form form={form} layout="vertical" initialValues={initialValues} preserve={false}>
        <Form.Item
          name="connection_id"
          label={__('Send using connection')}
          rules={[{ required: true, message: __('Select a connection') }]}
        >
          <Select options={connectionOptions} placeholder={__('Select a connection')} />
        </Form.Item>
        <Form.Item
          name="to"
          label={__('To')}
          rules={[{ required: true, message: __('At least one recipient is required') }]}
        >
          <Select
            mode="tags"
            open={false}
            tokenSeparators={[',', ' ']}
            placeholder="recipient@example.com"
          />
        </Form.Item>
        <Form.Item
          name="subject"
          label={__('Subject')}
          rules={[{ required: true, message: __('Subject is required') }]}
        >
          <Input />
        </Form.Item>
        <Form.Item name="cc" label={__('Cc')}>
          <Select mode="tags" open={false} tokenSeparators={[',', ' ']} />
        </Form.Item>
        <Form.Item name="bcc" label={__('Bcc')}>
          <Select mode="tags" open={false} tokenSeparators={[',', ' ']} />
        </Form.Item>
      </Form>
    </Modal>
  )
}
