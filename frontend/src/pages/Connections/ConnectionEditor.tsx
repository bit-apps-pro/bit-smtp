import { type ReactNode, useState } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import request from '@common/helpers/request'
import notify from '@components/Toaster/Toaster'
import config from '@config/config'
import { type Connection, type ProviderMeta, type WebhookProvisioning } from '@pages/Connections/types'
import { Alert, Button, Flex, Form, Input, Switch, Typography, theme } from 'antd'
import ConnectionTestButton, { ConnectionTestOutcome } from './ConnectionTestButton'
import OAuthConnectButton from './OAuthConnectButton'
import ProviderFields from './ProviderFields'
import useSaveConnection from './data/useSaveConnection'
import { type ConnectionTestResult } from './data/useTestConnection'
import { getProviderVisual } from './providerVisuals'

const { Text, Paragraph } = Typography

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

  if (provider.supports_webhook === true) {
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

/** Renders webhook health: provisioning outcome, verification state, and last received event. */
function WebhookHealth({
  provisioning,
  verified,
  lastEventAt,
  providerLabel
}: {
  provisioning: WebhookProvisioning | null | undefined
  verified: boolean
  lastEventAt: string | undefined
  providerLabel: string
}) {
  const { token } = theme.useToken()

  switch (provisioning?.status) {
    case 'registered':
      return verified ? (
        <>
          <Text style={{ display: 'block', color: token.colorSuccess }}>
            {__('✓ Webhook active — verified')}
          </Text>
          {lastEventAt ? (
            <Text type="secondary" style={{ display: 'block', fontSize: '0.85em' }}>
              {__('Last event: %s').replace('%s', lastEventAt)}
            </Text>
          ) : null}
        </>
      ) : (
        <Text style={{ color: token.colorWarning }}>{__('Registered — awaiting first event')}</Text>
      )
    case 'failed':
      return (
        <Alert
          type="warning"
          showIcon
          message={__('Webhook registration failed')}
          description={`${provisioning.reason ?? __('Unknown error')} — ${__(
            'Re-check the API key and save again.'
          )}`}
        />
      )
    case 'unsupported':
      return (
        <Alert
          type="info"
          showIcon
          message={__('Manual setup needed for %s').replace('%s', providerLabel)}
          description={__(
            'Automatic registration isn’t available for this provider — register the URL above manually in the provider’s dashboard. For Amazon SES, subscribe an SNS topic (bounce/complaint/delivery) to this URL.'
          )}
        />
      )
    case 'unavailable':
      return (
        <Alert
          type="info"
          showIcon
          message={__('Delivery webhooks unavailable')}
          description={__(
            'Delivery webhooks require a public HTTPS site; delivery status will show Accepted only.'
          )}
        />
      )
    default:
      return (
        <Text style={{ color: verified ? token.colorSuccess : token.colorWarning }}>
          {verified ? __('✓ Verified — receiving events') : __('Waiting for first event')}
        </Text>
      )
  }
}

function WebhookPanel({ connection, provider }: { connection: Connection; provider: ProviderMeta }) {
  const { token } = theme.useToken()
  const secret = (connection.settings?.webhook_secret as string | undefined) ?? ''
  // Prefer the server-composed URL; fall back to composing it locally only if the API omitted it.
  const webhookUrl =
    connection.webhook_url || (secret ? `${config.ROOT_URL}/bit-smtp/${connection.id}/${secret}` : '')
  const verified = Boolean(connection.settings?.webhook_verified)
  const [creating, setCreating] = useState(false)

  const createProviderWebhook = async () => {
    setCreating(true)
    try {
      const response = await request<{ created: boolean; id: string }>({
        action: 'mail/connections/webhook/create',
        data: { id: connection.id }
      })
      if (response.status === 'success') {
        notify.success(response.message || __('Webhook ready'))
      } else {
        notify.error(response.message || __('Failed to create webhook'))
      }
    } catch {
      notify.error(__('Failed to create webhook'))
    } finally {
      setCreating(false)
    }
  }

  return (
    <div
      style={{
        border: `1px solid ${token.colorBorderSecondary}`,
        borderRadius: token.borderRadius,
        padding: 12,
        marginTop: 12
      }}
    >
      <Text type="secondary" style={{ display: 'block', marginBottom: 4 }}>
        {__('Paste this into %s → Settings → Webhooks.').replace('%s', provider.label)}
      </Text>
      {webhookUrl ? (
        <Paragraph copyable={{ text: webhookUrl }} style={{ marginBottom: 8, wordBreak: 'break-all' }}>
          {webhookUrl}
        </Paragraph>
      ) : (
        <Text type="secondary" style={{ display: 'block', marginBottom: 8 }}>
          {__('Save this connection to generate its webhook URL.')}
        </Text>
      )}
      <WebhookHealth
        provisioning={connection.webhook_provisioning}
        verified={verified}
        lastEventAt={connection.settings?.webhook_last_event_at as string | undefined}
        providerLabel={provider.label}
      />
      {provider.supports_webhook_provisioning === true && connection.id && (
        <Button
          type="primary"
          size="small"
          loading={creating}
          onClick={createProviderWebhook}
          style={{ marginTop: 10 }}
        >
          {__('Create webhook in %s').replace('%s', provider.label)}
        </Button>
      )}
      <div>
        <Text type="secondary" style={{ fontSize: '0.85em' }}>
          {__('A provider’s “send test” won’t flip this — only a real tracked send does.')}
        </Text>
      </div>
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
  const [testResult, setTestResult] = useState<ConnectionTestResult>()
  const [draftId, setDraftId] = useState(connection.id)

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
  const webhookEnabled =
    (Form.useWatch('webhook_enabled', form) as boolean | undefined) ??
    (connection.settings?.webhook_enabled as boolean | undefined) ??
    true
  const oauthField = provider.fields.find(field => field.type === 'oauth')
  const inputFields = provider.fields.filter(field => field.type !== 'oauth')
  const supportsWebhook = provider.supports_webhook === true
  const hasProviderConfiguration =
    inputFields.length > 0 ||
    oauthField !== undefined ||
    provider.oauth_redirect_url !== undefined ||
    supportsWebhook

  const draftConnection: Connection = { ...connection, id: draftId }

  const buildPayload = () => buildConnectionPayload(form.getFieldsValue(true), draftConnection, provider)

  // Persist the current form values through the save endpoint and return the connection id. Always
  // saves — even for an existing connection — so a just-typed OAuth client_id/secret is stored before
  // the consent flow reads them; a new connection additionally captures and reuses the minted id so a
  // later Save updates instead of duplicating. Returns '' on any non-success body so the caller aborts
  // authorization rather than running consent against the stale stored credentials.
  const persistConnection = async (): Promise<string> => {
    const response = await mutateAsync(buildPayload())
    if (response?.status !== 'success') {
      notify.error(__('Failed to prepare this connection for OAuth. Please try again.'))
      return ''
    }

    const savedId = (response.data as { id?: string } | undefined)?.id ?? draftId
    if (savedId !== draftId) {
      setDraftId(savedId)
    }
    return savedId
  }

  const handleFinish = async (values: ConnectionFormValues) => {
    const response = await mutateAsync(buildConnectionPayload(values, draftConnection, provider))
    notify.success(__('Connection saved'))

    const webhook = (response?.data as { webhook?: { status?: string; message?: string } } | undefined)
      ?.webhook
    if (webhook?.status === 'warning' && webhook.message) {
      notify.warning(webhook.message)
    }

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

        {hasProviderConfiguration ? (
          <FormSection title={__('Credentials & settings')}>
            {provider.oauth_redirect_url ? (
              <Form.Item label={__('Redirect URI')}>
                <Paragraph
                  copyable={{ text: provider.oauth_redirect_url }}
                  style={{ marginBottom: 0, wordBreak: 'break-all' }}
                >
                  {provider.oauth_redirect_url}
                </Paragraph>
              </Form.Item>
            ) : null}
            <ProviderFields fields={inputFields} />
            {oauthField ? (
              <Form.Item label={oauthField.label}>
                <OAuthConnectButton
                  connectionId={draftId}
                  provider={provider.key}
                  connected={Boolean(connection.credentials?.refresh_token?.value)}
                  persistConnection={persistConnection}
                />
              </Form.Item>
            ) : null}
            {supportsWebhook ? (
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
            {supportsWebhook && webhookEnabled ? (
              <WebhookPanel connection={connection} provider={provider} />
            ) : null}
          </FormSection>
        ) : null}

        {testResult ? (
          <div style={{ marginTop: token.margin }}>
            <ConnectionTestOutcome result={testResult} />
          </div>
        ) : null}

        <Flex
          gap="small"
          justify="space-between"
          align="center"
          data-testid="connection-actions-bar"
          style={{
            position: 'sticky',
            bottom: 0,
            width: '100%',
            backgroundColor: token.colorBgContainer,
            borderTop: `1px solid ${token.colorBorderSecondary}`,
            boxShadow: token.boxShadowSecondary,
            padding: `${token.paddingSM}px ${token.padding}px`,
            marginTop: token.margin,
            zIndex: 10
          }}
        >
          <ConnectionTestButton getConnection={buildPayload} to={fromEmail} onResult={setTestResult} />
          <Button type="primary" htmlType="submit" size="large" loading={isPending}>
            {__('Save')}
          </Button>
        </Flex>
      </Form>
    </>
  )
}
