import { type ReactNode } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import useMaskedSecret from '@pages/Connections/fields/useMaskedSecret'
import { type AlertChannelSettings, type AlertChannelType } from '@pages/Connections/types'
import { Button, Form, Input, Select } from 'antd'
import { KeyRound, Mail, MessageCircle, MessageSquare, Send, Webhook } from 'lucide-react'
import {
  EMAIL_PATTERN,
  generateSigningSecret,
  isDiscordWebhookUrl,
  isHttpUrl,
  isSlackIncomingWebhookUrl,
  isTelegramBotToken,
  isValidSigningSecret,
  isValidTelegramChatId
} from './notificationChannels.helpers'

/** Props every per-channel Fields renderer receives: the channel's index in the `channels` Form.List. */
export interface ChannelFieldsProps {
  index: number
}

/** Form.Item name for one settings key, relative to the surrounding `channels` Form.List. */
function settingsName(index: number, key: keyof AlertChannelSettings) {
  return [index, 'settings', key]
}

/** Absolute form path for one settings key, for hooks that read the store outside Form.List scoping. */
export function settingsPath(index: number, key: keyof AlertChannelSettings) {
  return ['channels', index, 'settings', key]
}

/** Email channel fields: the recipient list, required for every added Email channel. */
function EmailFields({ index }: ChannelFieldsProps) {
  const validateRecipients = (_: unknown, recipients?: string[]) => {
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
      name={settingsName(index, 'recipients')}
      label={__('Recipients')}
      rules={[{ validator: validateRecipients }]}
    >
      <Select
        mode="tags"
        tokenSeparators={[',', ' ']}
        placeholder={__('alerts@example.com')}
        options={[]}
      />
    </Form.Item>
  )
}

/** Webhook channel fields: destination URL plus a generated or hand-entered HMAC signing secret. */
function WebhookFields({ index }: ChannelFieldsProps) {
  const form = Form.useFormInstance()
  const signingSecretMask = useMaskedSecret(settingsPath(index, 'signing_secret'))

  const validateWebhookUrl = (_: unknown, value?: string) => {
    if (!value) {
      return Promise.reject(new Error(__('Webhook URL is required')))
    }
    return isHttpUrl(value)
      ? Promise.resolve()
      : Promise.reject(new Error(__('Enter a valid HTTP or HTTPS URL')))
  }

  const validateSigningSecret = (_: unknown, value?: string) => {
    if (value && isValidSigningSecret(value)) {
      return Promise.resolve()
    }

    return Promise.reject(new Error(__('Generate or enter a valid signing secret')))
  }

  return (
    <>
      <Form.Item
        name={settingsName(index, 'url')}
        label={__('Webhook URL')}
        rules={[{ validator: validateWebhookUrl }]}
      >
        <Input
          type="url"
          autoComplete="off"
          inputMode="url"
          placeholder="https://example.com/hooks/bit-smtp"
        />
      </Form.Item>
      <Form.Item
        name={settingsName(index, 'signing_secret')}
        label={__('Signing secret')}
        rules={[{ validator: validateSigningSecret }]}
        extra={
          <Button
            type="link"
            size="small"
            icon={<KeyRound size={15} />}
            onClick={() =>
              form.setFieldValue(settingsPath(index, 'signing_secret'), generateSigningSecret())
            }
            style={{ paddingInline: 0 }}
          >
            {__('Generate signing secret')}
          </Button>
        }
      >
        <Input.Password
          autoComplete="new-password"
          placeholder="whsec_..."
          visibilityToggle={signingSecretMask.visibilityToggle}
          onFocus={signingSecretMask.onFocus}
          onBlur={signingSecretMask.onBlur}
        />
      </Form.Item>
    </>
  )
}

/** Props for a single-field channel whose only setting is a destination webhook URL. */
interface WebhookUrlFieldProps {
  index: number
  settingsKey: keyof AlertChannelSettings
  label: string
  placeholder: string
  validate: (value: string) => boolean
  invalidMessage: string
}

/** A single required, validated destination-URL field, shared by channel types with no other settings. */
function WebhookUrlField({
  index,
  settingsKey,
  label,
  placeholder,
  validate,
  invalidMessage
}: WebhookUrlFieldProps) {
  const validateUrl = (_: unknown, value?: string) => {
    if (value && validate(value.trim())) {
      return Promise.resolve()
    }

    return Promise.reject(new Error(invalidMessage))
  }

  return (
    <Form.Item
      name={settingsName(index, settingsKey)}
      label={label}
      rules={[{ validator: validateUrl }]}
    >
      <Input type="url" autoComplete="off" inputMode="url" placeholder={placeholder} />
    </Form.Item>
  )
}

/** Slack channel fields: the incoming-webhook URL, validated against Slack's exact URL shape. */
function SlackFields({ index }: ChannelFieldsProps) {
  return (
    <WebhookUrlField
      index={index}
      settingsKey="webhook_url"
      label={__('Slack webhook URL')}
      placeholder="https://hooks.slack.com/services/..."
      validate={isSlackIncomingWebhookUrl}
      invalidMessage={__('Enter a valid Slack incoming webhook URL')}
    />
  )
}

/** Telegram channel fields: bot token and target chat ID. */
function TelegramFields({ index }: ChannelFieldsProps) {
  const telegramBotTokenMask = useMaskedSecret(settingsPath(index, 'bot_token'))

  const validateTelegramBotToken = (_: unknown, value?: string) => {
    if (value && isTelegramBotToken(value.trim())) {
      return Promise.resolve()
    }

    return Promise.reject(new Error(__('Enter a valid Telegram bot token')))
  }

  const validateTelegramChatId = (_: unknown, value?: string) => {
    if (value && isValidTelegramChatId(value.trim())) {
      return Promise.resolve()
    }

    return Promise.reject(new Error(__('Enter a valid Telegram chat ID')))
  }

  return (
    <>
      <Form.Item
        name={settingsName(index, 'bot_token')}
        label={__('Telegram bot token')}
        rules={[{ validator: validateTelegramBotToken }]}
      >
        <Input.Password
          autoComplete="new-password"
          placeholder={__('123456:bot-token')}
          visibilityToggle={telegramBotTokenMask.visibilityToggle}
          onFocus={telegramBotTokenMask.onFocus}
          onBlur={telegramBotTokenMask.onBlur}
        />
      </Form.Item>
      <Form.Item
        name={settingsName(index, 'chat_id')}
        label={__('Telegram chat ID')}
        rules={[{ validator: validateTelegramChatId }]}
      >
        <Input autoComplete="off" placeholder="-1001234567890" />
      </Form.Item>
    </>
  )
}

/** Discord channel fields: the webhook URL, validated against Discord's exact URL shape. */
function DiscordFields({ index }: ChannelFieldsProps) {
  return (
    <WebhookUrlField
      index={index}
      settingsKey="webhook_url"
      label={__('Discord webhook URL')}
      placeholder="https://discord.com/api/webhooks/..."
      validate={isDiscordWebhookUrl}
      invalidMessage={__('Enter a valid Discord webhook URL')}
    />
  )
}

/** One notification channel type: identity, icon, blank settings for a new instance, and its field renderer. */
export interface ChannelDefinition {
  type: AlertChannelType
  label: string
  icon: typeof Mail
  emptySettings: AlertChannelSettings
  Fields: (props: ChannelFieldsProps) => ReactNode
}

/** The notification channel types the Notifications section can add, any number of times each. */
export const NOTIFICATION_CHANNELS: ChannelDefinition[] = [
  {
    type: 'email',
    label: __('Email'),
    icon: Mail,
    emptySettings: { recipients: [] },
    Fields: EmailFields
  },
  {
    type: 'webhook',
    label: __('Webhook'),
    icon: Webhook,
    emptySettings: { url: '', signing_secret: '' },
    Fields: WebhookFields
  },
  {
    type: 'slack',
    label: __('Slack'),
    icon: MessageSquare,
    emptySettings: { webhook_url: '' },
    Fields: SlackFields
  },
  {
    type: 'telegram',
    label: __('Telegram'),
    icon: Send,
    emptySettings: { bot_token: '', chat_id: '' },
    Fields: TelegramFields
  },
  {
    type: 'discord',
    label: __('Discord'),
    icon: MessageCircle,
    emptySettings: { webhook_url: '' },
    Fields: DiscordFields
  }
]

/** Look up a channel type's definition; undefined for a type this build doesn't know. */
export function channelDefinition(type: AlertChannelType): ChannelDefinition | undefined {
  return NOTIFICATION_CHANNELS.find(channel => channel.type === type)
}
