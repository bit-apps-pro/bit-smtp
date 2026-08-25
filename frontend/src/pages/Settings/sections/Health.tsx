import { __ } from '@common/helpers/i18nwrap'
import SettingsPanel, { PanelDivider } from '@pages/Settings/components/SettingsPanel'
import {
  HEALTH_ALERT_EVENTS,
  type HealthAlertEvent,
  type PreferencesFormValues
} from '@pages/Settings/types'
import { Form, type FormInstance, InputNumber, Select, Switch, Typography } from 'antd'

const { Text } = Typography

/**
 * Health preference fields: periodic connection health checks, their run interval, and — the actual
 * consumers of the health notifier — which transitions to alert on and how often to repeat an alert.
 */
export default function Health({ form }: { form: FormInstance<PreferencesFormValues> }) {
  const healthCheckEnabled = Form.useWatch('health_check_enabled', form) ?? false
  const selectedEvents = Form.useWatch('notify_events', form) ?? []
  const controlsDisabled = !healthCheckEnabled
  const noEventsSelected = healthCheckEnabled && selectedEvents.length === 0

  const eventLabels: Record<HealthAlertEvent, string> = {
    connection_unhealthy: __('Connection unhealthy'),
    connection_recovered: __('Connection recovered'),
    oauth_expiring: __('OAuth token expiring'),
    oauth_expired: __('OAuth token expired')
  }
  const eventOptions = HEALTH_ALERT_EVENTS.map(event => ({ value: event, label: eventLabels[event] }))

  return (
    <SettingsPanel
      intro={__('Periodically verify each connection can still send mail, and alert when one degrades.')}
    >
      <Form.Item name="health_check_enabled" label={__('Enable health checks')} valuePropName="checked">
        <Switch />
      </Form.Item>
      <PanelDivider />
      <Form.Item
        name="health_check_interval"
        label={__('Check interval')}
        extra={
          <Text type="secondary">
            {__('How often BitSMTP tests each connection in the background.')}
          </Text>
        }
      >
        <Select
          disabled={controlsDisabled}
          options={[
            { value: 'hourly', label: __('Hourly') },
            { value: 'twicedaily', label: __('Twice daily') },
            { value: 'daily', label: __('Daily') }
          ]}
        />
      </Form.Item>
      <PanelDivider />
      <Form.Item
        name="notify_events"
        label={__('Alert on these events')}
        extra={
          <>
            <Text type="secondary">
              {__('Which health and OAuth changes send an alert through your notification channels.')}
            </Text>
            {noEventsSelected && (
              <Text type="warning" style={{ display: 'block' }}>
                {__('No events selected — connection health alerts are off.')}
              </Text>
            )}
          </>
        }
      >
        <Select
          mode="multiple"
          disabled={controlsDisabled}
          options={eventOptions}
          placeholder={__('Select the events to be alerted about')}
        />
      </Form.Item>
      <PanelDivider />
      <Form.Item
        name="notify_cooldown_minutes"
        label={__('Alert cooldown (minutes)')}
        extra={
          <Text type="secondary">
            {__('Minimum time between repeat alerts for the same connection.')}
          </Text>
        }
        rules={[{ type: 'number', required: true, min: 0, message: __('Enter 0 or more minutes') }]}
      >
        <InputNumber disabled={controlsDisabled} style={{ width: '100%' }} />
      </Form.Item>
    </SettingsPanel>
  )
}
