import { type CSSProperties, type ReactNode, useEffect, useState } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import notify from '@components/Toaster/Toaster'
import useMailSettings from '@pages/Connections/data/useMailSettings'
import useUpdateSettings from '@pages/Connections/data/useUpdateSettings'
import TabLabel, { StatusDot } from '@pages/Settings/components/TabLabel'
import useAlertsSaveFlow, { type SaveOutcome } from '@pages/Settings/data/useAlertsSaveFlow'
import usePreferences, { useSavePreferences } from '@pages/Settings/data/usePreferences'
import GeneralLogging from '@pages/Settings/sections/GeneralLogging'
import Health from '@pages/Settings/sections/Health'
import NotificationsChannels, {
  type NotificationFormValues,
  toAlertsFormValues
} from '@pages/Settings/sections/NotificationsChannels'
import PrivacyData from '@pages/Settings/sections/PrivacyData'
import Reliability from '@pages/Settings/sections/Reliability'
import { type Preferences, type PreferencesFormValues } from '@pages/Settings/types'
import valuesEqual from '@pages/Settings/utils/valuesEqual'
import { Alert, Button, Flex, Form, Grid, Spin, Tabs, Typography, theme } from 'antd'
import { Activity, Bell, Gauge, Lock, type LucideIcon, ScrollText } from 'lucide-react'
import cls from './SettingsPage.module.css'

const { Title, Text } = Typography

/** Run one store's save (or skip it when `save` is null), turning a thrown request into an error outcome. */
async function runSave(save: (() => Promise<SaveOutcome>) | null): Promise<SaveOutcome | null> {
  if (!save) {
    return null
  }
  try {
    return await save()
  } catch {
    return { status: 'error' }
  }
}

type SettingsLayoutVars = CSSProperties & Record<`--settings-${string}`, string>

interface SectionDefinition {
  key: string
  icon: LucideIcon
  label: string
  enabled?: boolean
  content: ReactNode
}

/** Build one side-menu entry: its menu label plus the pane content under a section heading. */
function toTabItem({ key, icon, label, enabled, content }: SectionDefinition) {
  return {
    key,
    label: <TabLabel icon={icon} label={label} enabled={enabled} />,
    children: (
      <section aria-label={label}>
        <Title level={5} style={{ margin: 0 }}>
          {label}
        </Title>
        {content}
      </section>
    )
  }
}

/** Global settings screen: one Save action spanning the preferences and mail-settings alerts stores. */
export default function SettingsPage() {
  const { token } = theme.useToken()
  const [prefsForm] = Form.useForm<PreferencesFormValues>()
  const [alertsForm] = Form.useForm<NotificationFormValues>()
  const [isSaving, setIsSaving] = useState(false)
  const [activeTab, setActiveTab] = useState('general')
  const isWide = Grid.useBreakpoint().md ?? true

  const { data: preferences, isPending: preferencesPending } = usePreferences()
  const { data: settings, isPending: settingsPending, refetch: refetchSettings } = useMailSettings()
  const savePreferences = useSavePreferences()
  const updateSettings = useUpdateSettings()
  const {
    alertsConflict,
    isReloadingAlerts,
    reloadAlerts,
    buildAlertsPayload,
    adoptSavedAlerts,
    reportSaveFailure,
    reportInvalidFields
  } = useAlertsSaveFlow({
    alertsForm,
    settings,
    refetchSettings,
    onAlertsIssue: () => setActiveTab('notifications')
  })

  useEffect(() => {
    if (preferences) {
      prefsForm.setFieldsValue(preferences)
    }
  }, [prefsForm, preferences])

  const rawLoggingEnabled = Form.useWatch('logging_enabled', prefsForm)
  const rawRetryEnabled = Form.useWatch('retry_enabled', prefsForm)
  const rawHealthEnabled = Form.useWatch('health_check_enabled', prefsForm)
  const rawAlertsEnabled = Form.useWatch('enabled', alertsForm)

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

  const prefsInitialValues = preferences
  const alertsInitialValues = toAlertsFormValues(settings)
  const prefsDirty = Boolean(watchedPrefs && !valuesEqual(watchedPrefs, prefsInitialValues))
  const alertsDirty = Boolean(watchedAlerts && !valuesEqual(watchedAlerts, alertsInitialValues))
  const isDirty = prefsDirty || alertsDirty

  const loggingEnabled = rawLoggingEnabled ?? preferences.logging_enabled
  const retryEnabled = rawRetryEnabled ?? preferences.retry_enabled
  const healthEnabled = rawHealthEnabled ?? preferences.health_check_enabled
  const notificationsEnabled = rawAlertsEnabled ?? alertsInitialValues.enabled

  /** Validate and save only the store(s) that changed; a successful alerts save reseeds to avoid a stale revision. */
  const handleSave = async () => {
    setIsSaving(true)
    try {
      const [prefsValidation, alertsValidation] = await Promise.allSettled([
        prefsDirty ? prefsForm.validateFields() : Promise.resolve(),
        alertsDirty ? alertsForm.validateFields() : Promise.resolve()
      ])
      if (prefsValidation.status === 'rejected' || alertsValidation.status === 'rejected') {
        reportInvalidFields(alertsValidation.status === 'rejected')
        return
      }

      const prefsPayload = (): Preferences => ({ ...preferences, ...prefsForm.getFieldsValue() })
      const [prefsOutcome, alertsOutcome] = await Promise.all([
        runSave(prefsDirty ? () => savePreferences.mutateAsync(prefsPayload()) : null),
        runSave(alertsDirty ? () => updateSettings.mutateAsync(buildAlertsPayload(settings)) : null)
      ])
      const alertsReloaded = alertsOutcome?.status === 'success' ? await adoptSavedAlerts() : true

      const failed = [prefsOutcome, alertsOutcome].find(outcome => outcome?.status === 'error')
      if (failed) {
        reportSaveFailure(failed)
        return
      }
      if (alertsReloaded) {
        notify.success(__('Settings saved'))
      }
    } finally {
      setIsSaving(false)
    }
  }

  const sections: SectionDefinition[] = [
    {
      key: 'general',
      icon: ScrollText,
      label: __('General & Logging'),
      enabled: loggingEnabled,
      content: <GeneralLogging form={prefsForm} />
    },
    {
      key: 'reliability',
      icon: Gauge,
      label: __('Reliability'),
      enabled: retryEnabled,
      content: <Reliability form={prefsForm} />
    },
    {
      key: 'health',
      icon: Activity,
      label: __('Health'),
      enabled: healthEnabled,
      content: <Health form={prefsForm} />
    },
    {
      key: 'notifications',
      icon: Bell,
      label: __('Notifications'),
      enabled: notificationsEnabled,
      content: <NotificationsChannels alertsForm={alertsForm} settings={settings} />
    },
    {
      key: 'privacy',
      icon: Lock,
      label: __('Privacy & Data'),
      content: <PrivacyData />
    }
  ]

  const layoutVars: SettingsLayoutVars = {
    '--settings-radius': `${token.borderRadius}px`,
    '--settings-radius-lg': `${token.borderRadiusLG}px`,
    '--settings-item-color': token.colorTextSecondary,
    '--settings-item-hover-bg': token.colorFillTertiary,
    '--settings-item-active-bg': token.colorPrimaryBg,
    '--settings-card-bg': token.colorBgContainer,
    '--settings-card-border': token.colorBorderSecondary
  }

  return (
    <Form form={prefsForm} component={false} layout="vertical" initialValues={prefsInitialValues}>
      <Flex vertical style={{ padding: token.paddingLG }}>
        <Flex
          justify="space-between"
          align="flex-start"
          gap="middle"
          style={{ marginBottom: token.marginLG }}
        >
          <Flex vertical gap={4}>
            <Title level={4} style={{ margin: 0 }}>
              {__('Settings')}
            </Title>
            <Text type="secondary">
              {__('Global preferences for logging, delivery, health, notifications, and privacy.')}
            </Text>
          </Flex>
          <Flex align="center" gap="middle">
            {isDirty && (
              <Flex align="center" gap={8}>
                <StatusDot active activeColor={token.colorWarning} />
                <Text>{__('You have unsaved changes')}</Text>
              </Flex>
            )}
            <Button type="primary" loading={isSaving} disabled={!isDirty} onClick={handleSave}>
              {__('Save changes')}
            </Button>
          </Flex>
        </Flex>

        {alertsConflict && (
          <Alert
            type="error"
            showIcon
            message={alertsConflict}
            action={
              <Button size="small" loading={isReloadingAlerts} onClick={reloadAlerts}>
                {__('Reload')}
              </Button>
            }
            style={{ marginBottom: token.marginMD }}
          />
        )}

        <Tabs
          className={isWide ? cls.layout : `${cls.layout} ${cls.compact}`}
          style={layoutVars}
          tabPosition={isWide ? 'left' : 'top'}
          items={sections.map(toTabItem)}
          activeKey={activeTab}
          onChange={setActiveTab}
        />
      </Flex>
    </Form>
  )
}
