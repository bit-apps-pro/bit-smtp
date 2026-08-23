import { useEffect, useState } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import notify from '@components/Toaster/Toaster'
import useMailSettings from '@pages/Connections/data/useMailSettings'
import useUpdateSettings from '@pages/Connections/data/useUpdateSettings'
import { type MailSettings } from '@pages/Connections/types'
import { CONTENT_MAX_WIDTH } from '@pages/Settings/components/SettingsPanel'
import StatusStrip from '@pages/Settings/components/StatusStrip'
import TabLabel, { StatusDot } from '@pages/Settings/components/TabLabel'
import usePreferences, { useSavePreferences } from '@pages/Settings/data/usePreferences'
import GeneralLogging from '@pages/Settings/sections/GeneralLogging'
import Health from '@pages/Settings/sections/Health'
import NotificationsChannels, {
  type NotificationFormValues,
  toAlertsFormValues,
  toStoredAlerts
} from '@pages/Settings/sections/NotificationsChannels'
import PrivacyData from '@pages/Settings/sections/PrivacyData'
import Reliability from '@pages/Settings/sections/Reliability'
import { type Preferences, type PreferencesFormValues } from '@pages/Settings/types'
import valuesEqual from '@pages/Settings/utils/valuesEqual'
import { Button, Flex, Form, Spin, Tabs, Typography, theme } from 'antd'
import { Activity, Bell, Gauge, Lock, ScrollText } from 'lucide-react'

const { Title, Text } = Typography

/** A single store's save outcome, normalized from either mutation's echoed response. */
interface SaveOutcome {
  status: 'success' | 'error'
  message?: string
}

/** Split the loaded preferences blob into the subset the Form binds to (excludes untouched arrays). */
function toPreferencesFormValues(preferences: Preferences): PreferencesFormValues {
  return {
    logging_enabled: preferences.logging_enabled,
    log_retention_days: preferences.log_retention_days,
    log_store_body: preferences.log_store_body,
    send_timeout_seconds: preferences.send_timeout_seconds,
    retry_enabled: preferences.retry_enabled,
    retry_max_attempts: preferences.retry_max_attempts,
    retry_backoff: preferences.retry_backoff,
    health_check_enabled: preferences.health_check_enabled,
    health_check_interval: preferences.health_check_interval,
    notify_cooldown_minutes: preferences.notify_cooldown_minutes,
    uninstall_purge: preferences.uninstall_purge,
    tracking_enabled: preferences.tracking_enabled
  }
}

export default function SettingsPage() {
  const { token } = theme.useToken()
  const [prefsForm] = Form.useForm<PreferencesFormValues>()
  const [alertsForm] = Form.useForm<NotificationFormValues>()
  const [isSaving, setIsSaving] = useState(false)

  const { data: preferences, isPending: preferencesPending } = usePreferences()
  const { data: settings, isPending: settingsPending } = useMailSettings()
  const savePreferences = useSavePreferences()
  const updateSettings = useUpdateSettings()

  useEffect(() => {
    if (preferences) {
      prefsForm.setFieldsValue(toPreferencesFormValues(preferences))
    }
  }, [prefsForm, preferences])

  useEffect(() => {
    if (settings) {
      alertsForm.setFieldsValue(toAlertsFormValues(settings))
    }
  }, [alertsForm, settings])

  const loggingEnabled = Form.useWatch('logging_enabled', prefsForm) ?? true
  const retryEnabled = Form.useWatch('retry_enabled', prefsForm) ?? false
  // Raw watches (undefined until the owning tab pane first mounts) drive both the tab dots — via the
  // legacy `?? false` collapse, whose pre-visit behavior must stay unchanged — and the status strip,
  // which instead falls back to the saved value below so the readout is correct before any visit.
  const rawHealthEnabled = Form.useWatch('health_check_enabled', prefsForm)
  const healthCheckEnabled = rawHealthEnabled ?? false
  const rawAlertsEnabled = Form.useWatch('enabled', alertsForm)
  const alertsEnabled = rawAlertsEnabled ?? false
  const watchedRetention = Form.useWatch('log_retention_days', prefsForm)
  const watchedTimeout = Form.useWatch('send_timeout_seconds', prefsForm)

  // `preserve: true` reads all store values, not just fields whose Form.Item has mounted — tab
  // panes other than the active one are rendered lazily (antd Tabs default), so most fields aren't
  // registered until their tab is first visited.
  const watchedPrefs = Form.useWatch([], { form: prefsForm, preserve: true })
  const watchedAlerts = Form.useWatch([], { form: alertsForm, preserve: true })

  if (preferencesPending || settingsPending) {
    return (
      <Flex justify="center" style={{ padding: 48 }}>
        <Spin size="large" />
      </Flex>
    )
  }

  if (!preferences || !settings) {
    return null
  }

  // Single per-store snapshot of the loaded values, reused for both the dirty check (compared
  // against the live watch) and, below, the status-strip fallback / Form initialValues.
  const prefsInitialValues = toPreferencesFormValues(preferences)
  const alertsInitialValues = toAlertsFormValues(settings)
  const prefsDirty = Boolean(watchedPrefs && !valuesEqual(watchedPrefs, prefsInitialValues))
  const alertsDirty = Boolean(watchedAlerts && !valuesEqual(watchedAlerts, alertsInitialValues))
  const isDirty = prefsDirty || alertsDirty

  // Status-strip readouts: prefer the live edited value, fall back to the loaded/saved value (so a
  // never-visited tab still reads correctly).
  const retentionDays = watchedRetention ?? preferences.log_retention_days
  const timeoutSeconds = watchedTimeout ?? preferences.send_timeout_seconds
  const healthEnabled = rawHealthEnabled ?? preferences.health_check_enabled
  const notificationsEnabled = rawAlertsEnabled ?? alertsInitialValues.enabled

  /** Build a save payload for the mail-settings store from the alerts channels form. */
  const buildAlertsPayload = (): Partial<MailSettings> => ({
    features: { ...settings.features, alerts: toStoredAlerts(alertsForm.getFieldsValue()) }
  })

  /** Validate whichever store(s) changed, then save only those, as a single combined action. */
  const handleSave = async () => {
    setIsSaving(true)
    try {
      const validations = await Promise.allSettled([
        prefsDirty ? prefsForm.validateFields() : Promise.resolve(),
        alertsDirty ? alertsForm.validateFields() : Promise.resolve()
      ])
      if (validations.some(result => result.status === 'rejected')) {
        return // antd already rendered inline errors on the offending fields
      }

      const saves: Promise<SaveOutcome>[] = []
      if (prefsDirty) {
        const payload: Preferences = { ...preferences, ...prefsForm.getFieldsValue() }
        saves.push(savePreferences.mutateAsync(payload))
      }
      if (alertsDirty) {
        saves.push(updateSettings.mutateAsync(buildAlertsPayload()))
      }
      if (saves.length === 0) {
        return
      }

      const results = await Promise.all(saves)
      const failed = results.find(result => result.status === 'error')
      if (failed) {
        notify.error(failed.message || __('Failed to save settings'))
        return
      }
      notify.success(__('Settings saved'))
    } catch {
      notify.error(__('Failed to save settings'))
    } finally {
      setIsSaving(false)
    }
  }

  const items = [
    {
      key: 'general',
      label: <TabLabel icon={ScrollText} label={__('General & Logging')} dotActive={loggingEnabled} />,
      children: <GeneralLogging form={prefsForm} />
    },
    {
      key: 'reliability',
      label: <TabLabel icon={Gauge} label={__('Reliability')} dotActive={retryEnabled} />,
      children: <Reliability form={prefsForm} />
    },
    {
      key: 'health',
      label: <TabLabel icon={Activity} label={__('Health')} dotActive={healthCheckEnabled} />,
      children: <Health form={prefsForm} />
    },
    {
      key: 'notifications',
      label: <TabLabel icon={Bell} label={__('Notifications')} dotActive={alertsEnabled} />,
      children: (
        <NotificationsChannels
          alertsForm={alertsForm}
          prefsForm={prefsForm}
          prefsInitialValues={prefsInitialValues}
          settings={settings}
        />
      )
    },
    {
      key: 'privacy',
      label: <TabLabel icon={Lock} label={__('Privacy & Data')} />,
      children: <PrivacyData />
    }
  ]

  return (
    <Form form={prefsForm} component={false} layout="vertical" initialValues={prefsInitialValues}>
      <Flex vertical style={{ padding: token.paddingLG, paddingBottom: 0 }}>
        <Flex
          justify="space-between"
          align="flex-start"
          gap="middle"
          style={{ marginBottom: token.margin }}
        >
          <Flex vertical gap={4}>
            <Title level={4} style={{ margin: 0 }}>
              {__('Settings')}
            </Title>
            <Text type="secondary">
              {__('Global preferences for logging, delivery, health, notifications, and privacy.')}
            </Text>
          </Flex>
        </Flex>

        <div style={{ marginBottom: token.marginLG }}>
          <StatusStrip
            loggingEnabled={loggingEnabled}
            retentionDays={retentionDays}
            timeoutSeconds={timeoutSeconds}
            notificationsEnabled={notificationsEnabled}
            healthEnabled={healthEnabled}
          />
        </div>

        <Tabs type="line" items={items} />
      </Flex>

      <div
        style={{
          position: 'sticky',
          bottom: 0,
          backgroundColor: token.colorBgContainer,
          borderTop: `1px solid ${token.colorBorderSecondary}`,
          boxShadow: token.boxShadowSecondary,
          padding: `${token.paddingSM}px ${token.paddingLG}px`,
          marginTop: token.margin,
          zIndex: 10
        }}
      >
        <Flex
          justify="space-between"
          align="center"
          gap="middle"
          style={{ maxWidth: CONTENT_MAX_WIDTH, marginInline: 'auto' }}
        >
          <Flex align="center" gap={8}>
            {/* Amber (not success) dot: unsaved changes are an attention state, not a healthy one. */}
            {isDirty && <StatusDot active activeColor={token.colorWarning} />}
            <Text type={isDirty ? undefined : 'secondary'}>
              {isDirty ? __('You have unsaved changes') : __('All changes saved')}
            </Text>
          </Flex>
          <Button
            type="primary"
            size="large"
            loading={isSaving}
            disabled={!isDirty}
            onClick={handleSave}
          >
            {__('Save changes')}
          </Button>
        </Flex>
      </div>
    </Form>
  )
}
