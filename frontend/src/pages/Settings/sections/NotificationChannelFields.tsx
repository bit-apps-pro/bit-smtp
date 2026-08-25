import { type ReactNode } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import useMaskedSecret from '@pages/Connections/fields/useMaskedSecret'
import { MASK_SENTINEL } from '@pages/Connections/types'
import { type NotificationChannel } from '@pages/Settings/data/useTestNotification'
import { Button, Form, type FormInstance, Input, Select } from 'antd'
import { KeyRound, Mail, MessageCircle, MessageSquare, Send, Webhook } from 'lucide-react'
import { type NotificationFormValues } from './NotificationsChannels'
import {
  EMAIL_PATTERN,
  generateSigningSecret,
  isDiscordWebhookUrl,
  isSlackIncomingWebhookUrl,
  isTelegramBotToken,
  isValidSigningSecret,
  isValidTelegramChatId
} from './notificationChannels.helpers'

/** Props every per-channel Fields renderer receives: the shared alerts Form and whether its inputs are disabled. */
export interface ChannelFieldsProps {
  alertsForm: FormInstance<NotificationFormValues>
  disabled: boolean
}

/** Email channel fields: the recipient list, required once the channel is added while alerts are enabled. */
function EmailFields({ disabled }: Pick<ChannelFieldsProps, 'disabled'>) {
  const validateRecipients = (_: unknown, recipients?: string[]) => {
    if (disabled) {
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

  return (
    <Form.Item
      name="recipients"
      label={__('Recipients')}
      dependencies={['enabled']}
      rules={[{ validator: validateRecipients }]}
    >
      <Select
        mode="tags"
        tokenSeparators={[',', ' ']}
        placeholder={__('alerts@example.com')}
        disabled={disabled}
        options={[]}
      />
    </Form.Item>
  )
}

/** Unlabeled, invisible stand-in for `recipients` while Email isn't added — keeps it registered (and
 * thus present in `getFieldsValue()`/preserved by `initialValues`) without rendering visible UI. antd
 * only applies a field's `initialValues` entry once *some* Form.Item for that name mounts; without
 * this, a channel that's never been added would be missing from the saved payload entirely. */
function EmailHiddenFields() {
  return (
    <Form.Item name="recipients" hidden>
      <Select mode="tags" />
    </Form.Item>
  )
}

/** Webhook channel fields: destination URL plus a generated or hand-entered HMAC signing secret. */
function WebhookFields({ alertsForm, disabled }: ChannelFieldsProps) {
  const webhookUrlMask = useMaskedSecret('webhookUrl')
  const signingSecretMask = useMaskedSecret('signingSecret')

  const validateWebhookUrl = (_: unknown, value?: string) => {
    if (disabled) {
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
    if (disabled) {
      return Promise.resolve()
    }
    if (value && isValidSigningSecret(value)) {
      return Promise.resolve()
    }

    return Promise.reject(new Error(__('Generate or enter a valid signing secret')))
  }

  return (
    <>
      <Form.Item
        name="webhookUrl"
        label={__('Webhook URL')}
        dependencies={['enabled']}
        rules={[{ validator: validateWebhookUrl }]}
      >
        <Input.Password
          autoComplete="off"
          placeholder="https://example.com/hooks/bit-smtp"
          disabled={disabled}
          visibilityToggle={webhookUrlMask.visibilityToggle}
          onFocus={webhookUrlMask.onFocus}
          onBlur={webhookUrlMask.onBlur}
        />
      </Form.Item>
      <Form.Item
        name="signingSecret"
        label={__('Signing secret')}
        dependencies={['enabled']}
        rules={[{ validator: validateSigningSecret }]}
        extra={
          <Button
            type="link"
            size="small"
            icon={<KeyRound size={15} />}
            disabled={disabled}
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
          disabled={disabled}
          visibilityToggle={signingSecretMask.visibilityToggle}
          onFocus={signingSecretMask.onFocus}
          onBlur={signingSecretMask.onBlur}
        />
      </Form.Item>
    </>
  )
}

/** Unlabeled, invisible stand-in for `webhookUrl`/`signingSecret` while Webhook isn't added (see EmailHiddenFields). */
function WebhookHiddenFields() {
  return (
    <>
      <Form.Item name="webhookUrl" hidden>
        <Input />
      </Form.Item>
      <Form.Item name="signingSecret" hidden>
        <Input />
      </Form.Item>
    </>
  )
}

/** Slack channel fields: the incoming-webhook URL, validated against Slack's exact URL shape. */
function SlackFields({ disabled }: Pick<ChannelFieldsProps, 'disabled'>) {
  const slackWebhookUrlMask = useMaskedSecret('slackWebhookUrl')

  const validateSlackWebhookUrl = (_: unknown, value?: string) => {
    if (disabled) {
      return Promise.resolve()
    }
    if (value && isSlackIncomingWebhookUrl(value.trim())) {
      return Promise.resolve()
    }

    return Promise.reject(new Error(__('Enter a valid Slack incoming webhook URL')))
  }

  return (
    <Form.Item
      name="slackWebhookUrl"
      label={__('Slack webhook URL')}
      dependencies={['enabled']}
      rules={[{ validator: validateSlackWebhookUrl }]}
    >
      <Input.Password
        autoComplete="new-password"
        placeholder="https://hooks.slack.com/services/..."
        disabled={disabled}
        visibilityToggle={slackWebhookUrlMask.visibilityToggle}
        onFocus={slackWebhookUrlMask.onFocus}
        onBlur={slackWebhookUrlMask.onBlur}
      />
    </Form.Item>
  )
}

/** Unlabeled, invisible stand-in for `slackWebhookUrl` while Slack isn't added (see EmailHiddenFields). */
function SlackHiddenFields() {
  return (
    <Form.Item name="slackWebhookUrl" hidden>
      <Input />
    </Form.Item>
  )
}

/** Telegram channel fields: bot token and target chat ID. */
function TelegramFields({ disabled }: Pick<ChannelFieldsProps, 'disabled'>) {
  const telegramBotTokenMask = useMaskedSecret('telegramBotToken')

  const validateTelegramBotToken = (_: unknown, value?: string) => {
    if (disabled) {
      return Promise.resolve()
    }
    if (value && isTelegramBotToken(value.trim())) {
      return Promise.resolve()
    }

    return Promise.reject(new Error(__('Enter a valid Telegram bot token')))
  }

  const validateTelegramChatId = (_: unknown, value?: string) => {
    if (disabled) {
      return Promise.resolve()
    }
    if (value && isValidTelegramChatId(value.trim())) {
      return Promise.resolve()
    }

    return Promise.reject(new Error(__('Enter a valid Telegram chat ID')))
  }

  return (
    <>
      <Form.Item
        name="telegramBotToken"
        label={__('Telegram bot token')}
        dependencies={['enabled']}
        rules={[{ validator: validateTelegramBotToken }]}
      >
        <Input.Password
          autoComplete="new-password"
          placeholder={__('123456:bot-token')}
          disabled={disabled}
          visibilityToggle={telegramBotTokenMask.visibilityToggle}
          onFocus={telegramBotTokenMask.onFocus}
          onBlur={telegramBotTokenMask.onBlur}
        />
      </Form.Item>
      <Form.Item
        name="telegramChatId"
        label={__('Telegram chat ID')}
        dependencies={['enabled']}
        rules={[{ validator: validateTelegramChatId }]}
      >
        <Input autoComplete="off" placeholder="-1001234567890" disabled={disabled} />
      </Form.Item>
    </>
  )
}

/** Unlabeled, invisible stand-in for `telegramBotToken`/`telegramChatId` while Telegram isn't added (see EmailHiddenFields). */
function TelegramHiddenFields() {
  return (
    <>
      <Form.Item name="telegramBotToken" hidden>
        <Input />
      </Form.Item>
      <Form.Item name="telegramChatId" hidden>
        <Input />
      </Form.Item>
    </>
  )
}

/** Discord channel fields: the webhook URL, validated against Discord's exact URL shape. */
function DiscordFields({ disabled }: Pick<ChannelFieldsProps, 'disabled'>) {
  const discordWebhookUrlMask = useMaskedSecret('discordWebhookUrl')

  const validateDiscordWebhookUrl = (_: unknown, value?: string) => {
    if (disabled) {
      return Promise.resolve()
    }
    if (value && isDiscordWebhookUrl(value.trim())) {
      return Promise.resolve()
    }

    return Promise.reject(new Error(__('Enter a valid Discord webhook URL')))
  }

  return (
    <Form.Item
      name="discordWebhookUrl"
      label={__('Discord webhook URL')}
      dependencies={['enabled']}
      rules={[{ validator: validateDiscordWebhookUrl }]}
    >
      <Input.Password
        autoComplete="new-password"
        placeholder="https://discord.com/api/webhooks/..."
        disabled={disabled}
        visibilityToggle={discordWebhookUrlMask.visibilityToggle}
        onFocus={discordWebhookUrlMask.onFocus}
        onBlur={discordWebhookUrlMask.onBlur}
      />
    </Form.Item>
  )
}

/** Unlabeled, invisible stand-in for `discordWebhookUrl` while Discord isn't added (see EmailHiddenFields). */
function DiscordHiddenFields() {
  return (
    <Form.Item name="discordWebhookUrl" hidden>
      <Input />
    </Form.Item>
  )
}

export type ChannelKey = 'email' | 'webhook' | 'slack' | 'telegram' | 'discord'

/** One entry in the notification-channels registry: identity, icon, and the Form.Items it owns. */
export interface ChannelDefinition {
  key: ChannelKey
  label: string
  icon: typeof Mail
  enabledField: keyof NotificationFormValues
  // Values written back on Remove: flips the channel off and blanks its fields/secrets.
  clearValuesOnRemove: Partial<NotificationFormValues>
  testChannel?: NotificationChannel
  testLabel?: string
  Fields: (props: ChannelFieldsProps) => ReactNode
  // Invisible stand-in rendered instead of Fields while the channel isn't added, keeping its value
  // fields registered with the Form store (see EmailHiddenFields).
  HiddenFields: () => ReactNode
}

/** The notification channels the Notifications tab can add: identity, field renderer, and remove payload. */
export const NOTIFICATION_CHANNELS: ChannelDefinition[] = [
  {
    key: 'email',
    label: __('Email'),
    icon: Mail,
    enabledField: 'emailEnabled',
    clearValuesOnRemove: { emailEnabled: false, recipients: [] },
    testChannel: 'email',
    testLabel: __('Test email notification'),
    Fields: EmailFields,
    HiddenFields: EmailHiddenFields
  },
  {
    key: 'webhook',
    label: __('Webhook'),
    icon: Webhook,
    enabledField: 'webhookEnabled',
    clearValuesOnRemove: { webhookEnabled: false, webhookUrl: '', signingSecret: '' },
    testChannel: 'webhook',
    testLabel: __('Test webhook notification'),
    Fields: WebhookFields,
    HiddenFields: WebhookHiddenFields
  },
  {
    key: 'slack',
    label: __('Slack'),
    icon: MessageSquare,
    enabledField: 'slackEnabled',
    clearValuesOnRemove: { slackEnabled: false, slackWebhookUrl: '' },
    testChannel: 'slack',
    testLabel: __('Test Slack notification'),
    Fields: SlackFields,
    HiddenFields: SlackHiddenFields
  },
  {
    key: 'telegram',
    label: __('Telegram'),
    icon: Send,
    enabledField: 'telegramEnabled',
    clearValuesOnRemove: { telegramEnabled: false, telegramBotToken: '', telegramChatId: '' },
    testChannel: 'telegram',
    testLabel: __('Test Telegram notification'),
    Fields: TelegramFields,
    HiddenFields: TelegramHiddenFields
  },
  {
    key: 'discord',
    label: __('Discord'),
    icon: MessageCircle,
    enabledField: 'discordEnabled',
    clearValuesOnRemove: { discordEnabled: false, discordWebhookUrl: '' },
    testChannel: 'discord',
    testLabel: __('Test Discord notification'),
    Fields: DiscordFields,
    HiddenFields: DiscordHiddenFields
  }
]
