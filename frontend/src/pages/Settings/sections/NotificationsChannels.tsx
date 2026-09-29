import { type ReactNode, useState } from 'react'
import { BellOutlined } from '@ant-design/icons'
import { __ } from '@common/helpers/i18nwrap'
import { HelpfulFormItem } from '@components/FieldHelp'
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

/** Backend default for `reminder_minutes`, the shared "Repeat while the problem continues" interval. */
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
        {__('Add a channel, like email or Slack, to choose where alerts go.')}
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
        'WordPress background tasks (WP-Cron) are off on this site, so alerts only go out when your host runs wp-cron.php. Ask your host to set up a cron job.'
      ),
    delivery?.overdue &&
      __(
        'Some alerts are waiting to go out because WordPress background tasks (WP-Cron) haven’t run lately. Ask your host to check them.'
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
  /** The "Alert me about" checklist, owned by the preferences form. */
  alertEvents: ReactNode
}

/** Notifications section: the master alerts switch, what triggers an alert, how often it repeats, and channels. */
export default function NotificationsChannels({
  alertsForm,
  settings,
  alertEvents
}: NotificationsChannelsProps) {
  const { token } = theme.useToken()
  const [isPickerOpen, setIsPickerOpen] = useState(false)
  const [testingId, setTestingId] = useState<string | null>(null)
  const testNotification = useTestNotification()
  const alertsEnabled = Form.useWatch('enabled', alertsForm)

  const repeatOptions = [
    { value: 0, label: __('Don’t repeat') },
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
    <SettingsPanel intro={__('Get an alert when emails stop sending or a connection has a problem.')}>
      <Form
        form={alertsForm}
        component={false}
        layout="vertical"
        initialValues={toAlertsFormValues(settings)}
      >
        <HelpfulFormItem
          name="enabled"
          label={__('Send alerts')}
          hint={__('The main switch for every alert: failed emails and connection problems.')}
          valuePropName="checked"
        >
          <Switch />
        </HelpfulFormItem>
        {alertsEnabled && <DeliveryWarnings delivery={storedAlerts(settings)?.delivery} />}
        <div hidden={!alertsEnabled}>
          <PanelDivider />
          {alertEvents}
          <PanelDivider />
          <HelpfulFormItem
            name="reminder_minutes"
            label={__('Repeat while the problem continues')}
            hint={__("If a problem isn't fixed, remind me this often.")}
          >
            <Select options={repeatOptions} style={{ maxWidth: 240 }} />
          </HelpfulFormItem>
        </div>
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
