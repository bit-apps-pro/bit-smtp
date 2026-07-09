import { __ } from '@common/helpers/i18nwrap'
import notify from '@components/Toaster/Toaster'
import { type Connection, type ProviderMeta } from '@pages/Connections/types'
import { Button, Form, Input } from 'antd'
import ConnectionTestButton from './ConnectionTestButton'
import ProviderFields from './ProviderFields'
import useSaveConnection from './data/useSaveConnection'

interface ConnectionFormValues {
  name: string
  fromEmail: string
  fromName: string
  replyToEmail: string
  password?: string
  [settingKey: string]: unknown
}

function buildConnectionPayload(
  values: ConnectionFormValues,
  connection: Connection,
  provider: ProviderMeta
): Connection {
  const { name, fromEmail, fromName, replyToEmail, password } = values
  const settings = Object.fromEntries(
    provider.fields.filter(field => !field.secret).map(field => [field.key, values[field.key]])
  )

  return {
    id: connection.id,
    provider: provider.key,
    kind: provider.kind,
    name,
    enabled: connection.enabled ?? true,
    fromEmail,
    fromName,
    replyToEmail,
    settings,
    credentials: { password: { source: 'database', value: password ?? '' } }
  }
}

export default function ConnectionEditor({
  connection,
  provider,
  onSaved
}: {
  connection: Connection
  provider: ProviderMeta
  onSaved: () => void
}) {
  const [form] = Form.useForm<ConnectionFormValues>()
  const { mutateAsync, isPending } = useSaveConnection()

  const initialValues: ConnectionFormValues = {
    name: connection.name,
    fromEmail: connection.fromEmail,
    fromName: connection.fromName,
    replyToEmail: connection.replyToEmail,
    ...connection.settings,
    password: connection.credentials?.password?.value ?? ''
  }

  const fromEmail = Form.useWatch('fromEmail', form) ?? connection.fromEmail

  const buildPayload = () => buildConnectionPayload(form.getFieldsValue(true), connection, provider)

  const handleFinish = async (values: ConnectionFormValues) => {
    await mutateAsync(buildConnectionPayload(values, connection, provider))
    notify.success(__('Connection saved'))
    onSaved()
  }

  return (
    <Form form={form} layout="vertical" initialValues={initialValues} onFinish={handleFinish}>
      <Form.Item
        label={__('Name')}
        name="name"
        rules={[{ required: true, message: __('This field is required') }]}
      >
        <Input aria-label={__('Name')} />
      </Form.Item>
      <Form.Item
        label={__('From Email')}
        name="fromEmail"
        rules={[{ required: true, message: __('This field is required') }]}
      >
        <Input type="email" aria-label={__('From Email')} />
      </Form.Item>
      <Form.Item label={__('From Name')} name="fromName">
        <Input aria-label={__('From Name')} />
      </Form.Item>
      <Form.Item label={__('Reply-To Email')} name="replyToEmail">
        <Input type="email" aria-label={__('Reply-To Email')} />
      </Form.Item>
      <ProviderFields fields={provider.fields} />
      <Form.Item>
        <ConnectionTestButton getConnection={buildPayload} to={fromEmail} />
      </Form.Item>
      <Form.Item>
        <Button type="primary" htmlType="submit" loading={isPending}>
          {__('Save')}
        </Button>
      </Form.Item>
    </Form>
  )
}
