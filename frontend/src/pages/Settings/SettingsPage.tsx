import { useEffect } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import notify from '@components/Toaster/Toaster'
import usePreferences, { useSavePreferences } from '@pages/Settings/data/usePreferences'
import GeneralLogging from '@pages/Settings/sections/GeneralLogging'
import HealthNotifications from '@pages/Settings/sections/HealthNotifications'
import PrivacyData from '@pages/Settings/sections/PrivacyData'
import Reliability from '@pages/Settings/sections/Reliability'
import { type Preferences, type PreferencesFormValues } from '@pages/Settings/types'
import { Button, Flex, Form, Spin, Typography, theme } from 'antd'
import { Save } from 'lucide-react'

const { Title, Text } = Typography

/** Split the loaded preferences blob into the subset the Form binds to (excludes untouched arrays). */
function toFormValues(preferences: Preferences): PreferencesFormValues {
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
  const [form] = Form.useForm<PreferencesFormValues>()
  const { data: preferences, isPending } = usePreferences()
  const savePreferences = useSavePreferences()

  useEffect(() => {
    if (preferences) {
      form.setFieldsValue(toFormValues(preferences))
    }
  }, [form, preferences])

  if (isPending) {
    return (
      <Flex justify="center" style={{ padding: 48 }}>
        <Spin size="large" />
      </Flex>
    )
  }

  if (!preferences) {
    return null
  }

  const handleFinish = (values: PreferencesFormValues) => {
    const payload: Preferences = { ...preferences, ...values }
    savePreferences.mutate(payload, {
      onSuccess: response => {
        if (response.status === 'success') {
          notify.success(__('Preferences saved'))
        } else {
          notify.error(response.message || __('Failed to save preferences'))
        }
      },
      onError: () => notify.error(__('Failed to save preferences'))
    })
  }

  return (
    <Form
      form={form}
      layout="vertical"
      initialValues={toFormValues(preferences)}
      onFinish={handleFinish}
      style={{ maxWidth: 720, padding: token.paddingLG, paddingBottom: 0 }}
    >
      <Flex vertical gap="small" style={{ marginBottom: token.margin }}>
        <Title level={4} style={{ margin: 0 }}>
          {__('Settings')}
        </Title>
        <Text type="secondary">
          {__('Global preferences for logging, delivery reliability, health checks, and data privacy.')}
        </Text>
      </Flex>

      <Flex vertical gap="middle">
        <GeneralLogging form={form} />
        <Reliability form={form} />
        <HealthNotifications form={form} />
        <PrivacyData />
      </Flex>

      <Flex
        justify="flex-end"
        style={{
          position: 'sticky',
          bottom: 0,
          backgroundColor: token.colorBgContainer,
          borderTop: `1px solid ${token.colorBorderSecondary}`,
          boxShadow: token.boxShadowSecondary,
          padding: `${token.paddingSM}px ${token.padding}px`,
          marginTop: token.margin,
          marginInline: -token.paddingLG,
          zIndex: 10
        }}
      >
        <Button
          type="primary"
          htmlType="submit"
          size="large"
          icon={<Save size={16} />}
          loading={savePreferences.isPending}
        >
          {__('Save')}
        </Button>
      </Flex>
    </Form>
  )
}
