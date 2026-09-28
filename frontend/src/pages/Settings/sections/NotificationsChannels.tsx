import { useState } from 'react'
import { BellOutlined } from '@ant-design/icons'
import { __ } from '@common/helpers/i18nwrap'
import notify from '@components/Toaster/Toaster'
import {
  type AlertChannel,
  type AlertChannelSettings,
  type AlertChannelType,
  type AlertDeliveryStatus,
  type FailureAlertSettings,
  type MailSettings,
  type StoredAlertSettings
} from '@pages/Connections/types'
import SettingsPanel, { PanelDivider } from '@pages/Settings/components/SettingsPanel'
import useTestNotification from '@pages/Settings/data/useTestNotification'
import {
  Alert,
  Button,
  Flex,
  Form,
  type FormInstance,
  Select,
  Switch,
  Tooltip,
  Typography,
  theme
} from 'antd'
import NotificationChannelCard from './NotificationChannelCard'
import { NOTIFICATION_CHANNELS, channelDefinition, settingsPath } from './NotificationChannelFields'
import NotificationChannelPickerModal from './NotificationChannelPickerModal'
import { defaultChannelName, newChannelId } from './notificationChannels.helpers'

const { Title, Text } = Typography

/** Per-site channel cap, mirrored from backend (AlertSettingsNormalizer drops extras on save). */
export const MAX_CHANNELS = 20

/** Backend default for `reminder_minutes`. */
export const DEFAULT_REMINDER_MINUTES = 60

export type NotificationFormValues = FailureAlertSettings

/** The stored `features.alerts` blob with its server metadata, or undefined before alerts are configured. */
export function storedAlerts(settings: MailSettings): StoredAlertSettings | undefined {
  const raw = settings.features.alerts

  return Array.isArray(raw) ? undefined : raw
}

/** Read the mail-settings `features.alerts` blob (the pre-seed `[]` included) into the editable shape. */
function readAlerts(settings: MailSettings): FailureAlertSettings {
  const alerts = storedAlerts(settings)

  return {
    enabled: alerts?.enabled ?? false,
    reminder_minutes: alerts?.reminder_minutes ?? DEFAULT_REMINDER_MINUTES,
    channels: alerts?.channels ?? []
  }
}

/** Seed the Notifications Form from the stored alerts settings. */
export function toAlertsFormValues(settings: MailSettings): NotificationFormValues {
  return readAlerts(settings)
}

/** Trim every string setting (and recipient) so whitespace never reaches the backend validators. */
function trimSettings({ recipients, ...rest }: AlertChannelSettings): AlertChannelSettings {
  const trimmed: AlertChannelSettings = Object.fromEntries(
    Object.entries(rest).map(([key, value]) => [key, typeof value === 'string' ? value.trim() : value])
  )

  return recipients
    ? { ...trimmed, recipients: recipients.map(recipient => recipient.trim()).filter(Boolean) }
    : trimmed
}

/** Normalize the Form values into the stored `features.alerts` shape. */
export function toStoredAlerts(values: NotificationFormValues): FailureAlertSettings {
  return {
    enabled: values.enabled,
    reminder_minutes: values.reminder_minutes,
    channels: (values.channels ?? []).map(channel => ({
      ...channel,
      name: channel.name.trim(),
      settings: trimSettings(channel.settings)
    }))
  }
}

interface EmptyChannelsProps {
  onAddChannel: () => void
}

/** Dashed-border empty state shown when no notification channel has been added yet — mirrors EmptyConnections. */
function EmptyChannels({ onAddChannel }: EmptyChannelsProps) {
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
      <Button type="primary" onClick={onAddChannel} style={{ marginTop: token.marginXS }}>
        {__('Add channel')}
      </Button>
    </Flex>
  )
}

interface DeliveryWarningsProps {
  delivery?: AlertDeliveryStatus
}

/** Warn when the server reports that queued alerts can't go out promptly because WP-Cron isn't running. */
function DeliveryWarnings({ delivery }: DeliveryWarningsProps) {
  const { token } = theme.useToken()
  const warnings = [
    delivery?.cron_disabled &&
      __(
        'WP-Cron is disabled on this site (DISABLE_WP_CRON). Alerts are sent only when a real cron job runs wp-cron.php.'
      ),
    delivery?.overdue &&
      __(
        "Some alerts are waiting to be sent. WP-Cron hasn't run recently — check that your site's cron is working."
      )
  ].filter((warning): warning is string => Boolean(warning))

  if (warnings.length === 0) {
    return null
  }

  return (
    <Flex vertical gap="small" style={{ marginBottom: token.marginSM }}>
      {warnings.map(warning => (
        <Alert key={warning} type="warning" showIcon message={warning} />
      ))}
    </Flex>
  )
}

interface NotificationsChannelsProps {
  alertsForm: FormInstance<NotificationFormValues>
  settings: MailSettings
}

/** Notifications section: failure-alerts master switch plus channel instances; SettingsPage orchestrates the save. */
export default function NotificationsChannels({ alertsForm, settings }: NotificationsChannelsProps) {
  const { token } = theme.useToken()
  const [isPickerOpen, setIsPickerOpen] = useState(false)
  const [testingId, setTestingId] = useState<string | null>(null)
  const testNotification = useTestNotification()
  const alertsEnabled = Form.useWatch('enabled', alertsForm)

  const reminderOptions = [
    { value: 0, label: __('Never') },
    { value: 15, label: __('Every 15 minutes') },
    { value: 30, label: __('Every 30 minutes') },
    { value: 60, label: __('Every hour') },
    { value: 240, label: __('Every 4 hours') },
    { value: 1440, label: __('Once a day') }
  ]

  const openPicker = () => setIsPickerOpen(true)
  const closePicker = () => setIsPickerOpen(false)

  /** Build a blank, enabled instance of `type`, named so it stays distinguishable from its siblings. */
  const createChannel = (type: AlertChannelType): AlertChannel => {
    const definition = channelDefinition(type)
    const existing: AlertChannel[] = alertsForm.getFieldValue('channels') ?? []

    return {
      id: newChannelId(),
      type,
      name: defaultChannelName(
        definition?.label ?? type,
        existing.filter(channel => channel.type === type)
      ),
      enabled: true,
      settings: { ...definition?.emptySettings }
    }
  }

  /** Validate one card's fields, then send a test alert from its current (unsaved) values. */
  const handleTest = async (index: number) => {
    const channel: AlertChannel = alertsForm.getFieldValue(['channels', index])
    const settingKeys = Object.keys(channelDefinition(channel.type)?.emptySettings ?? {})
    try {
      await alertsForm.validateFields(
        settingKeys.map(key => settingsPath(index, key as keyof AlertChannelSettings))
      )
    } catch {
      return
    }

    setTestingId(channel.id)
    testNotification.mutate(
      { channel: { id: channel.id, type: channel.type, settings: trimSettings(channel.settings) } },
      {
        onSuccess: result => {
          if (result.ok) {
            notify.success(__('Test notification sent'))
            return
          }
          notify.error(result.error || __('Notification test failed'))
        },
        onError: () => notify.error(__('Notification test failed')),
        onSettled: () => setTestingId(null)
      }
    )
  }

  return (
    <SettingsPanel intro={__('Get notified the moment delivery starts failing.')}>
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
              {__(
                'Get one alert when sending starts failing and another when it recovers, with how many emails failed in between.'
              )}
            </Text>
          }
        >
          <Switch />
        </Form.Item>
        {alertsEnabled && <DeliveryWarnings delivery={storedAlerts(settings)?.delivery} />}
        <PanelDivider />
        <Form.Item
          name="reminder_minutes"
          label={__('Remind me while still failing')}
          extra={
            <Text type="secondary">
              {__('Repeat the alert with a running count until sending recovers.')}
            </Text>
          }
        >
          <Select options={reminderOptions} style={{ maxWidth: 240 }} />
        </Form.Item>
        <PanelDivider />

        <Form.List name="channels">
          {(fields, { add, remove }) => (
            <>
              <Flex justify="space-between" align="center" style={{ marginBottom: token.marginSM }}>
                <Title level={5} style={{ margin: 0 }}>
                  {__('Channels')}
                </Title>
                {fields.length > 0 && (
                  <Tooltip
                    title={
                      fields.length >= MAX_CHANNELS
                        ? __('You can add up to 20 channels. Remove one to add another.')
                        : undefined
                    }
                  >
                    <Button disabled={fields.length >= MAX_CHANNELS} onClick={openPicker}>
                      {__('Add channel')}
                    </Button>
                  </Tooltip>
                )}
              </Flex>

              {fields.length > 0 ? (
                <Flex vertical gap="middle">
                  {fields.map(field => {
                    const channel: AlertChannel = alertsForm.getFieldValue(['channels', field.name])
                    const definition = channelDefinition(channel.type)
                    if (!definition) {
                      return null
                    }

                    return (
                      <NotificationChannelCard
                        key={field.key}
                        index={field.name}
                        definition={definition}
                        onRemove={() => remove(field.name)}
                        onTest={() => handleTest(field.name)}
                        testLoading={testingId === channel.id}
                        testDisabled={testingId !== null && testingId !== channel.id}
                      />
                    )
                  })}
                </Flex>
              ) : (
                <EmptyChannels onAddChannel={openPicker} />
              )}

              <NotificationChannelPickerModal
                open={isPickerOpen}
                channels={NOTIFICATION_CHANNELS}
                onClose={closePicker}
                onSelect={type => add(createChannel(type))}
              />
            </>
          )}
        </Form.List>
      </Form>
    </SettingsPanel>
  )
}
