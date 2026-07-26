import { useEffect } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import notify from '@components/Toaster/Toaster'
import useMailSettings from '@pages/Connections/data/useMailSettings'
import useUpdateSettings from '@pages/Connections/data/useUpdateSettings'
import { type FailureAlertSettings, MASK_SENTINEL, type MailSettings } from '@pages/Connections/types'
import { Button, Divider, Flex, Form, Input, Select, Spin, Switch, Typography, theme } from 'antd'
import { KeyRound, Save } from 'lucide-react'

const { Text, Title } = Typography
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const SIGNING_SECRET_PATTERN = /^whsec_[A-Za-z0-9_-]{32,128}$/

interface NotificationFormValues {
  enabled: boolean
  emailEnabled: boolean
  recipients: string[]
  webhookEnabled: boolean
  webhookUrl: string
  signingSecret: string
}

function readAlerts(settings: MailSettings): FailureAlertSettings {
  const { alerts } = settings.features

  return {
    enabled: alerts?.enabled ?? false,
    email: {
      enabled: alerts?.email?.enabled ?? false,
      recipients: alerts?.email?.recipients ?? []
    },
    webhook: {
      enabled: alerts?.webhook?.enabled ?? false,
      url: alerts?.webhook?.url ?? '',
      signing_secret: alerts?.webhook?.signing_secret ?? ''
    }
  }
}

function toFormValues(settings: MailSettings): NotificationFormValues {
  const alerts = readAlerts(settings)

  return {
    enabled: alerts.enabled,
    emailEnabled: alerts.email.enabled,
    recipients: alerts.email.recipients,
    webhookEnabled: alerts.webhook.enabled,
    webhookUrl: alerts.webhook.url,
    signingSecret: alerts.webhook.signing_secret
  }
}

function generateSigningSecret(): string {
  const bytes = new Uint8Array(32)
  globalThis.crypto.getRandomValues(bytes)

  return `whsec_${Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('')}`
}

function toStoredAlerts(values: NotificationFormValues): FailureAlertSettings {
  return {
    enabled: values.enabled,
    email: {
      enabled: values.emailEnabled,
      recipients: values.recipients.map(recipient => recipient.trim()).filter(Boolean)
    },
    webhook: {
      enabled: values.webhookEnabled,
      url: values.webhookUrl.trim(),
      signing_secret: values.signingSecret.trim()
    }
  }
}

export default function NotificationsPage() {
  const { token } = theme.useToken()
  const [form] = Form.useForm<NotificationFormValues>()
  const { data: settings, isPending } = useMailSettings()
  const updateSettings = useUpdateSettings()
  const alertsEnabled = Form.useWatch('enabled', form) ?? false
  const emailEnabled = Form.useWatch('emailEnabled', form) ?? false
  const webhookEnabled = Form.useWatch('webhookEnabled', form) ?? false

  useEffect(() => {
    if (settings) {
      form.setFieldsValue(toFormValues(settings))
    }
  }, [form, settings])

  if (isPending) {
    return (
      <Flex justify="center" style={{ padding: 48 }}>
        <Spin size="large" />
      </Flex>
    )
  }

  if (!settings) {
    return null
  }

  const handleSave = (values: NotificationFormValues) => {
    updateSettings.mutate(
      {
        features: {
          ...settings.features,
          alerts: toStoredAlerts(values)
        }
      },
      { onSuccess: () => notify.success(__('Notification settings saved')) }
    )
  }

  const validateRecipients = (_: unknown, recipients?: string[]) => {
    if (!alertsEnabled || !emailEnabled) {
      return Promise.resolve()
    }
    if (!recipients?.length) {
      return Promise.reject(new Error(__('Add at least one recipient')))
    }
    if (recipients.some(recipient => !EMAIL_PATTERN.test(recipient.trim()))) {
      return Promise.reject(new Error(__('Enter valid email addresses')))
    }

    return Promise.resolve()
  }

  const validateWebhookUrl = (_: unknown, value?: string) => {
    if (!alertsEnabled || !webhookEnabled) {
      return Promise.resolve()
    }
    if (!value) {
      return Promise.reject(new Error(__('Webhook URL is required')))
    }
    if (value === MASK_SENTINEL) {
      return Promise.resolve()
    }

    try {
      const url = new URL(value)
      if (url.protocol === 'http:' || url.protocol === 'https:') {
        return Promise.resolve()
      }
    } catch {
      // The validation error below covers malformed URLs.
    }

    return Promise.reject(new Error(__('Enter a valid HTTP or HTTPS URL')))
  }

  const validateSigningSecret = (_: unknown, value?: string) => {
    if (!alertsEnabled || !webhookEnabled) {
      return Promise.resolve()
    }
    if (value === MASK_SENTINEL || (value && SIGNING_SECRET_PATTERN.test(value))) {
      return Promise.resolve()
    }

    return Promise.reject(new Error(__('Generate or enter a valid signing secret')))
  }

  return (
    <Form
      form={form}
      layout="vertical"
      initialValues={toFormValues(settings)}
      onFinish={handleSave}
      style={{ maxWidth: 760, padding: token.paddingLG }}
    >
      <Flex justify="space-between" align="center" gap="middle">
        <div>
          <Title level={4} style={{ margin: 0 }}>
            {__('Failure notifications')}
          </Title>
          <Text type="secondary">
            {__('Notify once when sending starts failing. A successful send resets the notification.')}
          </Text>
        </div>
        <Button
          type="primary"
          htmlType="submit"
          icon={<Save size={16} />}
          loading={updateSettings.isPending}
        >
          {__('Save')}
        </Button>
      </Flex>

      <Divider />

      <Form.Item name="enabled" label={__('Failure notifications')} valuePropName="checked">
        <Switch />
      </Form.Item>

      <Divider orientation="left">{__('Email')}</Divider>
      <Form.Item name="emailEnabled" label={__('Email notification')} valuePropName="checked">
        <Switch disabled={!alertsEnabled} />
      </Form.Item>
      <Form.Item
        name="recipients"
        label={__('Recipients')}
        dependencies={['enabled', 'emailEnabled']}
        rules={[{ validator: validateRecipients }]}
      >
        <Select
          mode="tags"
          tokenSeparators={[',', ' ']}
          placeholder={__('alerts@example.com')}
          disabled={!alertsEnabled || !emailEnabled}
          options={[]}
        />
      </Form.Item>

      <Divider orientation="left">{__('Webhook')}</Divider>
      <Form.Item name="webhookEnabled" label={__('Webhook notification')} valuePropName="checked">
        <Switch disabled={!alertsEnabled} />
      </Form.Item>
      <Form.Item
        name="webhookUrl"
        label={__('Webhook URL')}
        dependencies={['enabled', 'webhookEnabled']}
        rules={[{ validator: validateWebhookUrl }]}
      >
        <Input.Password
          autoComplete="off"
          placeholder="https://example.com/hooks/bit-smtp"
          disabled={!alertsEnabled || !webhookEnabled}
        />
      </Form.Item>
      <Form.Item
        name="signingSecret"
        label={__('Signing secret')}
        dependencies={['enabled', 'webhookEnabled']}
        rules={[{ validator: validateSigningSecret }]}
        extra={
          <Button
            type="link"
            size="small"
            icon={<KeyRound size={15} />}
            disabled={!alertsEnabled || !webhookEnabled}
            onClick={() => form.setFieldValue('signingSecret', generateSigningSecret())}
            style={{ paddingInline: 0 }}
          >
            {__('Generate signing secret')}
          </Button>
        }
      >
        <Input.Password
          autoComplete="new-password"
          placeholder="whsec_..."
          disabled={!alertsEnabled || !webhookEnabled}
        />
      </Form.Item>
    </Form>
  )
}
