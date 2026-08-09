import { useEffect } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import notify from '@components/Toaster/Toaster'
import useMailSettings from '@pages/Connections/data/useMailSettings'
import useUpdateSettings from '@pages/Connections/data/useUpdateSettings'
import { type FailureAlertSettings, MASK_SENTINEL, type MailSettings } from '@pages/Connections/types'
import { Button, Divider, Flex, Form, Input, Select, Spin, Switch, Typography, theme } from 'antd'
import { KeyRound, Save } from 'lucide-react'
import useTestNotification, {
  type NotificationChannel,
  type TestNotificationResult
} from './data/useTestNotification'

const { Text, Title } = Typography
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const SIGNING_SECRET_PATTERN = /^whsec_[A-Za-z0-9_-]{32,128}$/
const TELEGRAM_BOT_TOKEN_PATTERN = /^\d{6,20}:[A-Za-z0-9_-]{20,}$/
const TELEGRAM_CHAT_ID_PATTERN = /^-?\d{1,20}$/

interface NotificationFormValues {
  enabled: boolean
  emailEnabled: boolean
  recipients: string[]
  webhookEnabled: boolean
  webhookUrl: string
  signingSecret: string
  slackEnabled: boolean
  slackWebhookUrl: string
  telegramEnabled: boolean
  telegramBotToken: string
  telegramChatId: string
}

function readAlerts(settings: MailSettings): FailureAlertSettings {
  const raw = settings.features.alerts
  const alerts = Array.isArray(raw) ? undefined : raw

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
    },
    slack: {
      enabled: alerts?.slack?.enabled ?? false,
      webhook_url: alerts?.slack?.webhook_url ?? ''
    },
    telegram: {
      enabled: alerts?.telegram?.enabled ?? false,
      bot_token: alerts?.telegram?.bot_token ?? '',
      chat_id: alerts?.telegram?.chat_id ?? ''
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
    signingSecret: alerts.webhook.signing_secret,
    slackEnabled: alerts.slack.enabled,
    slackWebhookUrl: alerts.slack.webhook_url,
    telegramEnabled: alerts.telegram.enabled,
    telegramBotToken: alerts.telegram.bot_token,
    telegramChatId: alerts.telegram.chat_id
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
    },
    slack: {
      enabled: values.slackEnabled,
      webhook_url: values.slackWebhookUrl.trim()
    },
    telegram: {
      enabled: values.telegramEnabled,
      bot_token: values.telegramBotToken.trim(),
      chat_id: values.telegramChatId.trim()
    }
  }
}

function isSlackIncomingWebhookUrl(value: string): boolean {
  if (value === MASK_SENTINEL) {
    return true
  }
  if (
    !value.startsWith('https://hooks.slack.com/services/') ||
    value.includes('?') ||
    value.includes('#')
  ) {
    return false
  }

  try {
    const url = new URL(value)
    return (
      url.protocol === 'https:' &&
      url.hostname === 'hooks.slack.com' &&
      url.port === '' &&
      url.username === '' &&
      url.password === '' &&
      url.search === '' &&
      url.hash === '' &&
      url.pathname.startsWith('/services/') &&
      url.pathname.length > '/services/'.length
    )
  } catch {
    return false
  }
}

function isTelegramBotToken(value: string): boolean {
  return value === MASK_SENTINEL || TELEGRAM_BOT_TOKEN_PATTERN.test(value)
}

export default function NotificationsPage() {
  const { token } = theme.useToken()
  const [form] = Form.useForm<NotificationFormValues>()
  const { data: settings, isPending } = useMailSettings()
  const updateSettings = useUpdateSettings()
  const alertsEnabled = Form.useWatch('enabled', form) ?? false
  const emailEnabled = Form.useWatch('emailEnabled', form) ?? false
  const webhookEnabled = Form.useWatch('webhookEnabled', form) ?? false
  const slackEnabled = Form.useWatch('slackEnabled', form) ?? false
  const telegramEnabled = Form.useWatch('telegramEnabled', form) ?? false
  const testNotification = useTestNotification()

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

  const validateSlackWebhookUrl = (_: unknown, value?: string) => {
    if (!alertsEnabled || !slackEnabled) {
      return Promise.resolve()
    }
    if (value && isSlackIncomingWebhookUrl(value.trim())) {
      return Promise.resolve()
    }

    return Promise.reject(new Error(__('Enter a valid Slack incoming webhook URL')))
  }

  const validateTelegramBotToken = (_: unknown, value?: string) => {
    if (!alertsEnabled || !telegramEnabled) {
      return Promise.resolve()
    }
    if (value && isTelegramBotToken(value.trim())) {
      return Promise.resolve()
    }

    return Promise.reject(new Error(__('Enter a valid Telegram bot token')))
  }

  const validateTelegramChatId = (_: unknown, value?: string) => {
    if (!alertsEnabled || !telegramEnabled) {
      return Promise.resolve()
    }
    if (value && TELEGRAM_CHAT_ID_PATTERN.test(value.trim())) {
      return Promise.resolve()
    }

    return Promise.reject(new Error(__('Enter a valid Telegram chat ID')))
  }

  const savedAlerts = readAlerts(settings)
  const canTestSlack =
    alertsEnabled &&
    slackEnabled &&
    savedAlerts.slack.enabled &&
    isSlackIncomingWebhookUrl(savedAlerts.slack.webhook_url)
  const canTestTelegram =
    alertsEnabled &&
    telegramEnabled &&
    savedAlerts.telegram.enabled &&
    isTelegramBotToken(savedAlerts.telegram.bot_token) &&
    TELEGRAM_CHAT_ID_PATTERN.test(savedAlerts.telegram.chat_id)

  const handleTest = (channel: NotificationChannel) => {
    testNotification.mutate(
      { channel },
      {
        onSuccess: (result: TestNotificationResult) => {
          if (result.ok) {
            notify.success(__('Test notification sent'))
            return
          }
          notify.error(__('Notification test failed'))
        },
        onError: () => {
          notify.error(__('Notification test failed'))
        }
      }
    )
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

      <Divider orientation="left">{__('Slack')}</Divider>
      <Form.Item name="slackEnabled" label={__('Slack notification')} valuePropName="checked">
        <Switch disabled={!alertsEnabled} />
      </Form.Item>
      <Form.Item
        name="slackWebhookUrl"
        label={__('Slack webhook URL')}
        dependencies={['enabled', 'slackEnabled']}
        rules={[{ validator: validateSlackWebhookUrl }]}
      >
        <Input.Password
          autoComplete="new-password"
          placeholder="https://hooks.slack.com/services/..."
          disabled={!alertsEnabled || !slackEnabled}
        />
      </Form.Item>
      <Button
        onClick={() => handleTest('slack')}
        disabled={!canTestSlack || testNotification.isPending}
        loading={testNotification.isPending}
      >
        {__('Test Slack notification')}
      </Button>

      <Divider orientation="left">{__('Telegram')}</Divider>
      <Form.Item name="telegramEnabled" label={__('Telegram notification')} valuePropName="checked">
        <Switch disabled={!alertsEnabled} />
      </Form.Item>
      <Form.Item
        name="telegramBotToken"
        label={__('Telegram bot token')}
        dependencies={['enabled', 'telegramEnabled']}
        rules={[{ validator: validateTelegramBotToken }]}
      >
        <Input.Password
          autoComplete="new-password"
          placeholder={__('123456:bot-token')}
          disabled={!alertsEnabled || !telegramEnabled}
        />
      </Form.Item>
      <Form.Item
        name="telegramChatId"
        label={__('Telegram chat ID')}
        dependencies={['enabled', 'telegramEnabled']}
        rules={[{ validator: validateTelegramChatId }]}
      >
        <Input
          autoComplete="off"
          placeholder="-1001234567890"
          disabled={!alertsEnabled || !telegramEnabled}
        />
      </Form.Item>
      <Button
        onClick={() => handleTest('telegram')}
        disabled={!canTestTelegram || testNotification.isPending}
        loading={testNotification.isPending}
      >
        {__('Test Telegram notification')}
      </Button>
    </Form>
  )
}
