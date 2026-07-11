import { __ } from '@common/helpers/i18nwrap'
import notify from '@components/Toaster/Toaster'
import { type Connection, type ProviderMeta } from '@pages/Connections/types'
import { Button, Form, Input } from 'antd'
import ConnectionTestButton from './ConnectionTestButton'
import OAuthConnectButton from './OAuthConnectButton'
import ProviderFields from './ProviderFields'
import useSaveConnection from './data/useSaveConnection'

interface ConnectionFormValues {
  name: string
  fromEmail: string
  fromName: string
  replyToEmail: string
  [settingKey: string]: unknown
}

function buildConnectionPayload(
  values: ConnectionFormValues,
  connection: Connection,
  provider: ProviderMeta
): Connection {
  const { name, fromEmail, fromName, replyToEmail } = values
  const settings = Object.fromEntries(
    provider.fields
      .filter(field => !field.secret && field.type !== 'oauth')
      .map(field => [field.key, values[field.key]])
  )
  const credentials = Object.fromEntries(
    provider.fields
      .filter(field => field.secret && field.type !== 'oauth')
      .map(field => [
        field.key,
        { source: 'database', value: (values[field.key] as string | undefined) ?? '' }
      ])
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
    credentials
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

  const secretValues = Object.fromEntries(
    provider.fields
      .filter(field => field.secret && field.type !== 'oauth')
      .map(field => [field.key, connection.credentials?.[field.key]?.value ?? ''])
  )

  const initialValues: ConnectionFormValues = {
    name: connection.name,
    fromEmail: connection.fromEmail,
    fromName: connection.fromName,
    replyToEmail: connection.replyToEmail,
    ...connection.settings,
    ...secretValues
  }

  const fromEmail = Form.useWatch('fromEmail', form) ?? connection.fromEmail
  const oauthField = provider.fields.find(field => field.type === 'oauth')
  const inputFields = provider.fields.filter(field => field.type !== 'oauth')

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
      <ProviderFields fields={inputFields} />
      {oauthField ? (
        <Form.Item label={oauthField.label}>
          <OAuthConnectButton
            connectionId={connection.id}
            provider={provider.key}
            connected={Boolean(connection.credentials?.refresh_token?.value)}
          />
        </Form.Item>
      ) : null}
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
