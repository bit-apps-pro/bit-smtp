import { type ReactNode } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import notify from '@components/Toaster/Toaster'
import { type Connection, type ProviderMeta } from '@pages/Connections/types'
import { Button, Flex, Form, Input, Switch, Typography, theme } from 'antd'
import ConnectionTestButton from './ConnectionTestButton'
import OAuthConnectButton from './OAuthConnectButton'
import ProviderFields from './ProviderFields'
import useSaveConnection from './data/useSaveConnection'
import { getProviderVisual } from './providerVisuals'

const { Text } = Typography

const PROVIDER_BADGE_SIZE = 48

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

  if (provider.kind === 'api') {
    settings.webhook_enabled = values.webhook_enabled ?? true
  }

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

function ProviderHeader({ provider }: { provider: ProviderMeta }) {
  const { token } = theme.useToken()
  const visual = getProviderVisual(provider.key, provider.label)
  const haloSize = PROVIDER_BADGE_SIZE + 16

  return (
    <Flex align="center" gap={16} style={{ marginBottom: 16 }}>
      <Flex
        align="center"
        justify="center"
        aria-hidden="true"
        style={{
          width: haloSize,
          height: haloSize,
          borderRadius: '50%',
          backgroundColor: `${visual.accent}1F`,
          flexShrink: 0
        }}
      >
        {visual.logo ? (
          <img
            src={visual.logo}
            alt={provider.label}
            width={PROVIDER_BADGE_SIZE}
            height={PROVIDER_BADGE_SIZE}
            style={{ objectFit: 'contain' }}
          />
        ) : (
          <Flex
            align="center"
            justify="center"
            style={{
              width: PROVIDER_BADGE_SIZE,
              height: PROVIDER_BADGE_SIZE,
              borderRadius: token.borderRadius,
              backgroundColor: visual.accent,
              color: token.colorWhite,
              fontWeight: token.fontWeightStrong,
              fontSize: token.fontSizeLG
            }}
          >
            {visual.initial}
          </Flex>
        )}
      </Flex>
      <Flex vertical gap={2}>
        <Text strong style={{ fontSize: token.fontSizeXL }}>
          {provider.label}
        </Text>
        <Text type="secondary">{visual.blurb}</Text>
      </Flex>
    </Flex>
  )
}

function FormSection({ title, children }: { title: string; children: ReactNode }) {
  const { token } = theme.useToken()

  return (
    <div
      style={{
        backgroundColor: token.colorBgContainer,
        border: `1px solid ${token.colorBorderSecondary}`,
        borderRadius: token.borderRadiusLG,
        padding: 20,
        marginBottom: 16
      }}
    >
      <Text
        strong
        style={{
          display: 'block',
          fontSize: token.fontSizeSM,
          color: token.colorText,
          marginBottom: 12
        }}
      >
        {title}
      </Text>
      {children}
    </div>
  )
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
  const { token } = theme.useToken()
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
    webhook_enabled: connection.settings?.webhook_enabled ?? true,
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
    <>
      <ProviderHeader provider={provider} />
      <Form form={form} layout="vertical" initialValues={initialValues} onFinish={handleFinish}>
        <FormSection title={__('Identity')}>
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
        </FormSection>

        <FormSection title={__('Credentials & settings')}>
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
          {provider.kind === 'api' ? (
            <Form.Item
              name="webhook_enabled"
              label={__('Enable delivery webhook')}
              valuePropName="checked"
              extra={
                <Text type="secondary">
                  {__('Track real delivery status (delivered/bounced) via provider webhooks.')}
                </Text>
              }
            >
              <Switch />
            </Form.Item>
          ) : null}
        </FormSection>

        <Flex
          gap="small"
          justify="space-between"
          align="center"
          style={{
            position: 'sticky',
            bottom: 0,
            backgroundColor: token.colorBgContainer,
            borderTop: `1px solid ${token.colorBorderSecondary}`,
            boxShadow: token.boxShadowSecondary,
            padding: `${token.paddingSM}px ${token.padding}px`,
            marginTop: token.margin,
            zIndex: 10
          }}
        >
          <ConnectionTestButton getConnection={buildPayload} to={fromEmail} />
          <Button type="primary" htmlType="submit" size="large" loading={isPending}>
            {__('Save')}
          </Button>
        </Flex>
      </Form>
    </>
  )
}
