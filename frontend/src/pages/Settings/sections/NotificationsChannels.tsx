import { useState } from 'react'
import { BellOutlined } from '@ant-design/icons'
import { __ } from '@common/helpers/i18nwrap'
import notify from '@components/Toaster/Toaster'
import { type FailureAlertSettings, type MailSettings } from '@pages/Connections/types'
import SettingsPanel, { PanelDivider } from '@pages/Settings/components/SettingsPanel'
import useTestNotification, {
  type NotificationChannel,
  type TestNotificationResult
} from '@pages/Settings/data/useTestNotification'
import { type PreferencesFormValues } from '@pages/Settings/types'
import { Button, Flex, Form, type FormInstance, InputNumber, Switch, Typography, theme } from 'antd'
import NotificationChannelCard from './NotificationChannelCard'
import { type ChannelKey, NOTIFICATION_CHANNELS } from './NotificationChannelFields'
import NotificationChannelPickerModal from './NotificationChannelPickerModal'
import {
  isSlackIncomingWebhookUrl,
  isTelegramBotToken,
  isValidTelegramChatId
} from './notificationChannels.helpers'

const { Title, Text } = Typography

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

interface EmptyChannelsProps {
  disabled: boolean
  onAddChannel: () => void
}

/** Dashed-border empty state shown when no notification channel has been added yet — mirrors EmptyConnections. */
function EmptyChannels({ disabled, onAddChannel }: EmptyChannelsProps) {
  const { token } = theme.useToken()

  return (
    <Flex
      vertical
      align="center"
      gap="small"
      style={{
        padding: '64px 24px',
        textAlign: 'center',
        border: `1px dashed ${token.colorBorderSecondary}`,
        borderRadius: token.borderRadiusLG
      }}
    >
      <Flex
        align="center"
        justify="center"
        aria-hidden="true"
        style={{
          width: 56,
          height: 56,
          borderRadius: token.borderRadiusLG,
          backgroundColor: token.colorPrimaryBg,
          color: token.colorPrimary,
          fontSize: token.fontSizeHeading3
        }}
      >
        <BellOutlined />
      </Flex>
      <Title level={5} style={{ margin: 0 }}>
        {__('No notification channels yet')}
      </Title>
      <Text type="secondary" style={{ maxWidth: 360 }}>
        {__('Add a channel to get notified the moment delivery starts failing.')}
      </Text>
      <Button
        type="primary"
        disabled={disabled}
        onClick={onAddChannel}
        style={{ marginTop: token.marginXS }}
      >
        {__('Add channel')}
      </Button>
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
 * Notifications tab: the failure-alerts master switch, the cooldown field carried over from the
 * preferences store (`prefsForm`), and an "added channels" list (mail-settings store, `alertsForm`)
 * mirroring the Connections "add connection" UX — only channels the user has added render a card.
 * Saving both stores is orchestrated by the parent SettingsPage.
 */
export default function NotificationsChannels({
  alertsForm,
  prefsForm,
  prefsInitialValues,
  settings
}: NotificationsChannelsProps) {
  const { token } = theme.useToken()
  const [isPickerOpen, setIsPickerOpen] = useState(false)
  const testNotification = useTestNotification()

  const alertsEnabled = Form.useWatch('enabled', alertsForm) ?? false
  const emailEnabled = Form.useWatch('emailEnabled', alertsForm) ?? false
  const webhookEnabled = Form.useWatch('webhookEnabled', alertsForm) ?? false
  const slackEnabled = Form.useWatch('slackEnabled', alertsForm) ?? false
  const slackWebhookUrl = Form.useWatch('slackWebhookUrl', alertsForm) ?? ''
  const telegramEnabled = Form.useWatch('telegramEnabled', alertsForm) ?? false
  const telegramBotToken = Form.useWatch('telegramBotToken', alertsForm) ?? ''
  const telegramChatId = Form.useWatch('telegramChatId', alertsForm) ?? ''

  const addedByField: Record<string, boolean> = {
    emailEnabled,
    webhookEnabled,
    slackEnabled,
    telegramEnabled
  }
  const addedChannels = NOTIFICATION_CHANNELS.filter(channel => addedByField[channel.enabledField])
  const availableChannels = NOTIFICATION_CHANNELS.filter(channel => !addedByField[channel.enabledField])
  const hasChannels = addedChannels.length > 0
  const canAddMore = availableChannels.length > 0

  // Test gating mirrors the pre-redesign logic: a channel is testable only once its *saved* config is
  // valid and the live form hasn't drifted from it — independent of the master `enabled` switch.
  const savedAlerts = readAlerts(settings)
  const slackTargetIsUnsaved =
    slackEnabled !== savedAlerts.slack.enabled ||
    slackWebhookUrl.trim() !== savedAlerts.slack.webhook_url
  const telegramTargetIsUnsaved =
    telegramEnabled !== savedAlerts.telegram.enabled ||
    telegramBotToken.trim() !== savedAlerts.telegram.bot_token ||
    telegramChatId.trim() !== savedAlerts.telegram.chat_id
  const canTestByChannel: Partial<Record<ChannelKey, boolean>> = {
    slack:
      savedAlerts.slack.enabled &&
      isSlackIncomingWebhookUrl(savedAlerts.slack.webhook_url) &&
      !slackTargetIsUnsaved,
    telegram:
      savedAlerts.telegram.enabled &&
      isTelegramBotToken(savedAlerts.telegram.bot_token) &&
      isValidTelegramChatId(savedAlerts.telegram.chat_id) &&
      !telegramTargetIsUnsaved
  }

  const openPicker = () => setIsPickerOpen(true)
  const closePicker = () => setIsPickerOpen(false)

  /** Mark a channel as added; its card then renders with blank fields ready to configure. */
  const handleAddChannel = (key: ChannelKey) => {
    const channel = NOTIFICATION_CHANNELS.find(candidate => candidate.key === key)
    if (channel) {
      alertsForm.setFieldValue(channel.enabledField, true)
    }
  }

  /** Turn a channel off and blank its fields/secrets, so re-adding starts clean and nothing stale is saved. */
  const handleRemoveChannel = (key: ChannelKey) => {
    const channel = NOTIFICATION_CHANNELS.find(candidate => candidate.key === key)
    if (channel) {
      alertsForm.setFieldsValue(channel.clearValuesOnRemove)
    }
  }

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

        {/* Always mounted (just visually hidden) so each channel's "added" flag is registered with
            the Form store from the first render — a field whose Form.Item never mounts never picks
            up its `initialValues` entry, which would otherwise make every channel look "not added". */}
        {NOTIFICATION_CHANNELS.map(channel => (
          <Form.Item key={channel.key} name={channel.enabledField} valuePropName="checked" hidden>
            <Switch />
          </Form.Item>
        ))}

        {/* Same reasoning for each not-added channel's own value fields: without a stand-in, a
            channel that's never been added is missing from getFieldsValue() entirely, and
            toStoredAlerts() would crash trimming an undefined value at save time. */}
        {availableChannels.map(channel => (
          <channel.HiddenFields key={channel.key} />
        ))}

        <Flex justify="space-between" align="center" style={{ marginBottom: token.marginSM }}>
          <Title level={5} style={{ margin: 0 }}>
            {__('Channels')}
          </Title>
          {hasChannels && canAddMore && (
            <Button disabled={!alertsEnabled} onClick={openPicker}>
              {__('Add channel')}
            </Button>
          )}
        </Flex>

        {hasChannels ? (
          <Flex vertical gap="middle">
            {addedChannels.map(channel => (
              <NotificationChannelCard
                key={channel.key}
                channel={channel}
                alertsForm={alertsForm}
                disabled={!alertsEnabled}
                onRemove={() => handleRemoveChannel(channel.key)}
                onTest={
                  channel.testChannel
                    ? () => handleTest(channel.testChannel as NotificationChannel)
                    : undefined
                }
                testDisabled={!canTestByChannel[channel.key] || testNotification.isPending}
                testLoading={testNotification.isPending}
              />
            ))}
          </Flex>
        ) : (
          <EmptyChannels disabled={!alertsEnabled} onAddChannel={openPicker} />
        )}

        <NotificationChannelPickerModal
          open={isPickerOpen}
          channels={availableChannels}
          onClose={closePicker}
          onSelect={handleAddChannel}
        />
      </Form>
    </SettingsPanel>
  )
}
