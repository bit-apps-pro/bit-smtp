import { __ } from '@common/helpers/i18nwrap'
import notify from '@components/Toaster/Toaster'
import { type FailureAlertSettings, MASK_SENTINEL, type MailSettings } from '@pages/Connections/types'
import SettingsPanel, { PanelDivider } from '@pages/Settings/components/SettingsPanel'
import useTestNotification, {
  type NotificationChannel,
  type TestNotificationResult
} from '@pages/Settings/data/useTestNotification'
import { type PreferencesFormValues } from '@pages/Settings/types'
import {
  Button,
  Flex,
  Form,
  type FormInstance,
  Input,
  InputNumber,
  Select,
  Switch,
  Typography,
  theme
} from 'antd'
import { KeyRound, Mail, MessageSquare, Send, Webhook } from 'lucide-react'

const { Text } = Typography
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const SIGNING_SECRET_PATTERN = /^whsec_[A-Za-z0-9_-]{32,128}$/
const TELEGRAM_BOT_TOKEN_PATTERN = /^\d{6,20}:[A-Za-z0-9_-]{20,}$/
const TELEGRAM_CHAT_ID_PATTERN = /^-?\d{1,20}$/

export interface NotificationFormValues {
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

/** Normalize the mail-settings `features.alerts` blob (which may still be the pre-seed `[]`) into a full shape. */
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

/** Split the mail-settings alerts blob into the flat shape the Notifications channels Form binds to. */
export function toAlertsFormValues(settings: MailSettings): NotificationFormValues {
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

/** Re-nest the flat Notifications channels Form values back into the stored `features.alerts` shape. */
export function toStoredAlerts(values: NotificationFormValues): FailureAlertSettings {
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

/** Generate a webhook signing secret in the `whsec_...` shape the backend expects. */
function generateSigningSecret(): string {
  const bytes = new Uint8Array(32)
  globalThis.crypto.getRandomValues(bytes)

  return `whsec_${Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('')}`
}

/** Validate a Slack incoming-webhook URL, matching the backend's exact acceptance rules. */
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

/** Validate a Telegram bot token shape (or an already-masked saved value). */
function isTelegramBotToken(value: string): boolean {
  return value === MASK_SENTINEL || TELEGRAM_BOT_TOKEN_PATTERN.test(value)
}

interface ChannelHeadingProps {
  icon: typeof Mail
  title: string
}

/** Channel block heading: icon + title, used to separate Email/Webhook/Slack/Telegram field groups. */
function ChannelHeading({ icon: Icon, title }: ChannelHeadingProps) {
  const { token } = theme.useToken()

  return (
    <Flex align="center" gap={8} style={{ marginBottom: token.marginXS }}>
      <Icon size={16} color={token.colorTextSecondary} strokeWidth={1.75} aria-hidden="true" />
      <Text strong>{title}</Text>
    </Flex>
  )
}

interface NotificationsChannelsProps {
  alertsForm: FormInstance<NotificationFormValues>
  prefsForm: FormInstance<PreferencesFormValues>
  prefsInitialValues: PreferencesFormValues
  settings: MailSettings
}

/**
 * Notifications tab: the failure-alerts master switch and Email/Webhook/Slack/Telegram channels
 * (mail-settings store, `alertsForm`), plus the cooldown field carried over from the preferences
 * store (`prefsForm`). Saving both stores is orchestrated by the parent SettingsPage.
 */
export default function NotificationsChannels({
  alertsForm,
  prefsForm,
  prefsInitialValues,
  settings
}: NotificationsChannelsProps) {
  const alertsEnabled = Form.useWatch('enabled', alertsForm) ?? false
  const emailEnabled = Form.useWatch('emailEnabled', alertsForm) ?? false
  const webhookEnabled = Form.useWatch('webhookEnabled', alertsForm) ?? false
  const slackEnabled = Form.useWatch('slackEnabled', alertsForm) ?? false
  const slackWebhookUrl = Form.useWatch('slackWebhookUrl', alertsForm) ?? ''
  const telegramEnabled = Form.useWatch('telegramEnabled', alertsForm) ?? false
  const telegramBotToken = Form.useWatch('telegramBotToken', alertsForm) ?? ''
  const telegramChatId = Form.useWatch('telegramChatId', alertsForm) ?? ''
  const testNotification = useTestNotification()

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
  const slackTargetIsUnsaved =
    slackEnabled !== savedAlerts.slack.enabled ||
    slackWebhookUrl.trim() !== savedAlerts.slack.webhook_url
  const telegramTargetIsUnsaved =
    telegramEnabled !== savedAlerts.telegram.enabled ||
    telegramBotToken.trim() !== savedAlerts.telegram.bot_token ||
    telegramChatId.trim() !== savedAlerts.telegram.chat_id
  const canTestSlack =
    savedAlerts.slack.enabled &&
    isSlackIncomingWebhookUrl(savedAlerts.slack.webhook_url) &&
    !slackTargetIsUnsaved
  const canTestTelegram =
    savedAlerts.telegram.enabled &&
    isTelegramBotToken(savedAlerts.telegram.bot_token) &&
    TELEGRAM_CHAT_ID_PATTERN.test(savedAlerts.telegram.chat_id) &&
    !telegramTargetIsUnsaved

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
    <SettingsPanel
      intro={__('Get notified the moment delivery starts failing, and choose how often to repeat it.')}
    >
      <Form
        form={alertsForm}
        component={false}
        layout="vertical"
        initialValues={toAlertsFormValues(settings)}
      >
        <Form.Item
          name="enabled"
          label={__('Enable failure notifications')}
          valuePropName="checked"
          extra={
            <Text type="secondary">
              {__('Notify once when sending starts failing. A successful send resets the notification.')}
            </Text>
          }
        >
          <Switch />
        </Form.Item>
        <PanelDivider />

        {/* Re-shadow to the preferences form for this one field. Safe: it receives the same
            initialValues as the outer prefsForm mount in SettingsPage, so this redundant
            first-mount merge into the shared store is a no-op. */}
        <Form form={prefsForm} component={false} initialValues={prefsInitialValues}>
          <Form.Item
            name="notify_cooldown_minutes"
            label={__('Notification cooldown (minutes)')}
            extra={
              <Text type="secondary">{__('Minimum time between repeat failure notifications.')}</Text>
            }
            rules={[{ type: 'number', required: true, min: 0, message: __('Enter 0 or more minutes') }]}
          >
            <InputNumber disabled={!alertsEnabled} style={{ width: '100%' }} />
          </Form.Item>
        </Form>
        <PanelDivider />

        <ChannelHeading icon={Mail} title={__('Email')} />
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
        <PanelDivider />

        <ChannelHeading icon={Webhook} title={__('Webhook')} />
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
              onClick={() => alertsForm.setFieldValue('signingSecret', generateSigningSecret())}
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
        <PanelDivider />

        <ChannelHeading icon={MessageSquare} title={__('Slack')} />
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
        <PanelDivider />

        <ChannelHeading icon={Send} title={__('Telegram')} />
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
    </SettingsPanel>
  )
}
