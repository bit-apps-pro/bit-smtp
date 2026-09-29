import { type ReactNode, useId, useState } from 'react'
import { __, sprintf } from '@common/helpers/i18nwrap'
import useUnsavedChangesGuard from '@common/hooks/useUnsavedChangesGuard'
import { HelpfulFormItem } from '@components/FieldHelp'
import notify from '@components/Toaster/Toaster'
import config from '@config/config'
import { type Connection, type FieldMeta, type ProviderMeta } from '@pages/Connections/types'
import { Alert, Button, Flex, Form, Input, Modal, Switch, Typography, theme } from 'antd'
import ConnectionTestButton, { ConnectionTestOutcome } from './ConnectionTestButton'
import OAuthConnectButton from './OAuthConnectButton'
import PrerequisitesPanel from './PrerequisitesPanel'
import ProviderFields from './ProviderFields'
import ProviderLogo from './ProviderLogo'
import WebhookPanel from './WebhookPanel'
import useSaveConnection from './data/useSaveConnection'
import { type ConnectionTestResult } from './data/useTestConnection'
import { getFieldHint } from './fields/FieldItem'
import autoSetPatch from './fields/autoSet'
import { getProviderVisual } from './providerVisuals'

const { Text, Paragraph } = Typography

const PROVIDER_BADGE_SIZE = 48
const EDITOR_MAX_WIDTH = 640
const OAUTH_HTTP_HOSTS = new Set(['localhost', '127.0.0.1', '[::1]'])

/** Detects callback URLs that OAuth providers reject for lacking HTTPS. */
function isInsecureOAuthRedirect(url: string | undefined): boolean {
  if (!url) {
    return false
  }

  try {
    const redirect = new URL(url)
    return redirect.protocol !== 'https:' && !OAUTH_HTTP_HOSTS.has(redirect.hostname)
  } catch {
    return true
  }
}

interface ConnectionFormValues {
  name: string
  fromEmail: string
  fromName: string
  replyToEmail: string
  [settingKey: string]: unknown
}

type SaveResponse = Awaited<ReturnType<ReturnType<typeof useSaveConnection>['mutateAsync']>>

/** Maps form values into the Connection payload shape: identity fields, non-secret settings, secret credentials, optional webhook flag. */
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

/** Provider logo plus its label and one-line summary, heading the connection form. */
function ProviderHeader({ provider }: { provider: ProviderMeta }) {
  const { token } = theme.useToken()
  const visual = getProviderVisual(provider.key, provider.label)
  const tagline = provider.bestFor ?? visual.blurb

  return (
    <Flex align="center" gap={16} style={{ marginBottom: 16 }}>
      <ProviderLogo visual={visual} size={PROVIDER_BADGE_SIZE} />
      <Flex vertical gap={2}>
        <Text strong style={{ fontSize: token.fontSizeXL }}>
          {provider.label}
        </Text>
        {tagline && tagline !== provider.label ? <Text type="secondary">{tagline}</Text> : null}
      </Flex>
    </Flex>
  )
}

/** Bordered card grouping a related set of form fields under an accessible heading. */
function FormSection({ title, children }: { title: string; children: ReactNode }) {
  const { token } = theme.useToken()
  const headingId = useId()

  return (
    <section
      aria-labelledby={headingId}
      style={{
        backgroundColor: token.colorBgContainer,
        border: `1px solid ${token.colorBorderSecondary}`,
        borderRadius: token.borderRadiusLG,
        padding: 20,
        marginBottom: 16
      }}
    >
      <Text
        id={headingId}
        strong
        style={{ display: 'block', fontSize: token.fontSize, color: token.colorText, marginBottom: 12 }}
      >
        {title}
      </Text>
      {children}
    </section>
  )
}

/** Name, From and Reply-To inputs shared by every provider. */
function SenderSection({ provider }: { provider: ProviderMeta }) {
  const requiredRule = [{ required: true, message: __('This field is required') }]

  return (
    <FormSection title={__('Sender')}>
      <HelpfulFormItem
        label={__('Connection name')}
        name="name"
        rules={requiredRule}
        hint={__('Only you see this, for example “Brevo – receipts”.')}
      >
        <Input aria-label={__('Connection name')} />
      </HelpfulFormItem>
      <HelpfulFormItem
        label={__('From email')}
        name="fromEmail"
        rules={[...requiredRule, { type: 'email', message: __('Enter a valid email address') }]}
        hint={
          provider.fromEmailHint ??
          sprintf(__('Emails are sent from this address. %s must allow it.'), provider.label)
        }
      >
        <Input type="email" aria-label={__('From email')} />
      </HelpfulFormItem>
      <HelpfulFormItem
        label={__('From name')}
        name="fromName"
        hint={__('The sender name people see in their inbox, like your site name.')}
      >
        <Input aria-label={__('From name')} />
      </HelpfulFormItem>
      <HelpfulFormItem
        label={__('Reply-to email')}
        name="replyToEmail"
        rules={[{ type: 'email', message: __('Enter a valid email address') }]}
        hint={__('Where replies go, if not to the From address.')}
      >
        <Input type="email" aria-label={__('Reply-to email')} />
      </HelpfulFormItem>
    </FormSection>
  )
}

/** Copyable OAuth redirect URI plus the HTTPS warning when the site can't satisfy the provider. */
function OAuthRedirect({ url }: { url: string | undefined }) {
  if (!url) {
    return null
  }

  return (
    <>
      <HelpfulFormItem
        label={__('Redirect URI')}
        hint={__('Add this exact address as an authorized redirect URI in your OAuth app.')}
      >
        <Paragraph copyable={{ text: url }} style={{ marginBottom: 0, wordBreak: 'break-all' }}>
          {url}
        </Paragraph>
      </HelpfulFormItem>
      {isInsecureOAuthRedirect(url) ? (
        <Alert
          type="warning"
          showIcon
          style={{ marginBottom: 16 }}
          message={__('Your site needs HTTPS to sign in')}
          description={__(
            'Google and Microsoft only accept secure (https://) addresses. Turn on HTTPS for your site, then add the new address.'
          )}
        />
      ) : null}
    </>
  )
}

/** Delivery-tracking toggle and, when on, the webhook panel with its signing-secret fields. */
function TrackingSection({
  connection,
  provider,
  trackingFields,
  enabled
}: {
  connection: Connection
  provider: ProviderMeta
  trackingFields: FieldMeta[]
  enabled: boolean
}) {
  return (
    <FormSection title={__('Delivery tracking')}>
      <HelpfulFormItem
        name="webhook_enabled"
        label={__('Track delivery status')}
        valuePropName="checked"
        hint={sprintf(__('Get “delivered” and “bounced” updates from %s.'), provider.label)}
      >
        <Switch aria-label={__('Track delivery status')} />
      </HelpfulFormItem>
      {enabled ? (
        <WebhookPanel connection={connection} provider={provider} trackingFields={trackingFields} />
      ) : null}
    </FormSection>
  )
}

/** Each non-secret provider field's declared default, keyed by field, skipping fields that declare none. */
function fieldDefaults(provider: ProviderMeta): Record<string, unknown> {
  return Object.fromEntries(
    provider.fields
      .filter(field => !field.secret && field.type !== 'oauth' && field.default != null)
      .map(field => [field.key, field.default])
  )
}

/** Values a connection opens with: field defaults under its saved settings; new connections get the provider label as their name. */
function initialFormValues(connection: Connection, provider: ProviderMeta): ConnectionFormValues {
  const secretValues = Object.fromEntries(
    provider.fields
      .filter(field => field.secret && field.type !== 'oauth')
      .map(field => [field.key, connection.credentials?.[field.key]?.value ?? ''])
  )

  return {
    name: connection.name || provider.label,
    fromEmail: connection.fromEmail,
    fromName: connection.fromName,
    replyToEmail: connection.replyToEmail,
    webhook_enabled: connection.settings?.webhook_enabled ?? true,
    ...fieldDefaults(provider),
    ...connection.settings,
    ...secretValues
  }
}

/** Full connection create/edit form: provider credentials, sender, delivery tracking, test + save, and an unsaved-changes guard. */
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
  const [isDirty, setIsDirty] = useState(false)
  const guard = useUnsavedChangesGuard(isDirty)

  const fromEmail = (Form.useWatch('fromEmail', form) as string | undefined) ?? connection.fromEmail
  const webhookEnabled =
    (Form.useWatch('webhook_enabled', form) as boolean | undefined) ??
    (connection.settings?.webhook_enabled as boolean | undefined) ??
    true
  const oauthField = provider.fields.find(field => field.type === 'oauth')
  const inputFields = provider.fields.filter(field => field.type !== 'oauth')
  const credentialFields = inputFields.filter(field => field.section !== 'tracking')
  const trackingFields = inputFields.filter(field => field.section === 'tracking')
  const supportsWebhook = provider.supports_webhook === true
  const hasCredentials =
    credentialFields.length > 0 || oauthField !== undefined || provider.oauth_redirect_url !== undefined

  const draftConnection: Connection = { ...connection, id: draftId }

  const buildPayload = () => buildConnectionPayload(form.getFieldsValue(true), draftConnection, provider)

  /** Pins backend per-field validation messages onto the matching inputs. */
  const pinFieldErrors = (errors: Record<string, string>) => {
    const fieldErrors = Object.entries(errors).filter(([key]) =>
      inputFields.some(field => field.key === key)
    )
    form.setFields(fieldErrors.map(([name, message]) => ({ name, errors: [message] })))
  }

  /** Pins a rejected save's field errors and toasts its overall reason. */
  const showSaveErrors = (response: SaveResponse | undefined) => {
    pinFieldErrors((response?.data as { errors?: Record<string, string> } | undefined)?.errors ?? {})
    notify.error(response?.message ?? __('Failed to save connection'))
  }

  /** Always saves the current form first (so OAuth consent reads fresh, not stale, credentials) and reuses the minted id; returns '' to abort on failure. */
  const persistConnection = async (): Promise<string> => {
    const response = await mutateAsync(buildPayload())
    if (response?.status !== 'success') {
      showSaveErrors(response)
      return ''
    }

    setIsDirty(false)

    const savedId = (response.data as { id?: string } | undefined)?.id ?? draftId
    if (savedId !== draftId) {
      setDraftId(savedId)
    }
    return savedId
  }

  /** Validates the whole form before a test send; resolves the payload, or undefined when invalid. */
  const prepareTest = async (): Promise<Connection | undefined> => {
    try {
      await form.validateFields()
    } catch {
      return undefined
    }
    return buildPayload()
  }

  /** Keeps the latest test result and pins any per-field validation messages it carries. */
  const handleTestResult = (result: ConnectionTestResult | undefined) => {
    setTestResult(result)
    if (result?.fieldErrors) {
      pinFieldErrors(result.fieldErrors)
    }
  }

  /** Marks the form dirty and applies any declared auto-set (e.g. SMTP port from security). */
  const handleValuesChange = (changed: Partial<ConnectionFormValues>, values: ConnectionFormValues) => {
    setIsDirty(true)
    const patch = Object.entries(autoSetPatch(changed, values, inputFields))
    if (patch.length > 0) {
      form.setFields(patch.map(([name, value]) => ({ name, value })))
    }
  }

  /** Saves the form; on success toasts, surfaces any webhook set-up warning, and leaves the editor. */
  const handleFinish = async (values: ConnectionFormValues) => {
    const response = await mutateAsync(buildConnectionPayload(values, draftConnection, provider))
    if (response?.status !== 'success') {
      showSaveErrors(response)
      return
    }

    setIsDirty(false)
    notify.success(__('Connection saved'))

    const webhook = (response?.data as { webhook?: { status?: string; message?: string } } | undefined)
      ?.webhook
    if (webhook?.status === 'warning' && webhook.message) {
      notify.warning(webhook.message)
    }

    onSaved()
  }

  return (
    <div style={{ maxWidth: EDITOR_MAX_WIDTH, width: '100%' }}>
      <ProviderHeader provider={provider} />
      <PrerequisitesPanel provider={provider} />
      <Form
        form={form}
        layout="vertical"
        initialValues={initialFormValues(connection, provider)}
        onValuesChange={handleValuesChange}
        onFinish={handleFinish}
      >
        {provider.kind === 'local' ? (
          <Alert
            type="warning"
            showIcon
            style={{ marginBottom: 16 }}
            message={__('Best used only as a backup')}
            description={__(
              "This uses your web server's built-in mail. Many hosts block it, and inboxes often mark it as spam."
            )}
          />
        ) : null}

        {hasCredentials ? (
          <FormSection title={__('Account details')}>
            <OAuthRedirect url={provider.oauth_redirect_url} />
            <ProviderFields fields={credentialFields} />
            {oauthField ? (
              <HelpfulFormItem label={oauthField.label} hint={getFieldHint(oauthField)}>
                <OAuthConnectButton
                  connectionId={draftId}
                  provider={provider.key}
                  connected={Boolean(connection.credentials?.refresh_token?.value)}
                  persistConnection={persistConnection}
                />
              </HelpfulFormItem>
            ) : null}
          </FormSection>
        ) : null}

        <SenderSection provider={provider} />

        {supportsWebhook ? (
          <TrackingSection
            connection={connection}
            provider={provider}
            trackingFields={trackingFields}
            enabled={webhookEnabled}
          />
        ) : null}

        {testResult ? (
          <div style={{ marginBottom: token.margin }}>
            <ConnectionTestOutcome
              result={testResult}
              providerLabel={provider.label}
              testCaveat={provider.testCaveat}
              visibleFieldKeys={inputFields.map(field => field.key)}
            />
          </div>
        ) : null}

        <div
          data-testid="connection-actions-bar"
          style={{
            position: 'sticky',
            bottom: 0,
            backgroundColor: token.colorBgContainer,
            borderTop: `1px solid ${token.colorBorder}`,
            padding: `${token.paddingSM}px ${token.padding}px`,
            zIndex: 10
          }}
        >
          <ConnectionTestButton
            prepare={prepareTest}
            defaultRecipient={config.SEND_TO || fromEmail || ''}
            onResult={handleTestResult}
            trailingAction={
              <Button type="primary" htmlType="submit" loading={isPending}>
                {__('Save')}
              </Button>
            }
          />
        </div>
      </Form>
      <Modal
        open={guard.isLeavePending}
        title={__('Leave without saving?')}
        okText={__('Leave without saving')}
        okButtonProps={{ danger: true }}
        cancelText={__('Keep editing')}
        onOk={guard.leave}
        onCancel={guard.stay}
      >
        {__("Your changes to this connection haven't been saved and will be lost.")}
      </Modal>
    </div>
  )
}
