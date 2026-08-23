import { __ } from '@common/helpers/i18nwrap'
import { type PreferencesFormValues } from '@pages/Settings/types'
import { Card, Form, type FormInstance, InputNumber, Select, Switch, Typography } from 'antd'

const { Text } = Typography

/** Health & Notifications preference fields: connection health checks and alert cooldown. */
export default function HealthNotifications({ form }: { form: FormInstance<PreferencesFormValues> }) {
  const healthCheckEnabled = Form.useWatch('health_check_enabled', form) ?? false

  return (
    <Card title={__('Health & Notifications')}>
      <Form.Item
        name="health_check_enabled"
        label={__('Enable health checks')}
        valuePropName="checked"
        extra={
          <Text type="secondary">{__('Periodically verify each connection can still send mail.')}</Text>
        }
      >
        <Switch />
      </Form.Item>
      <Form.Item name="health_check_interval" label={__('Check interval')}>
        <Select
          disabled={!healthCheckEnabled}
          options={[
            { value: 'hourly', label: __('Hourly') },
            { value: 'twicedaily', label: __('Twice daily') },
            { value: 'daily', label: __('Daily') }
          ]}
        />
      </Form.Item>
      <Form.Item
        name="notify_cooldown_minutes"
        label={__('Notification cooldown (minutes)')}
        extra={<Text type="secondary">{__('Minimum time between repeat health alerts.')}</Text>}
        rules={[{ type: 'number', required: true, min: 0, message: __('Enter 0 or more minutes') }]}
      >
        <InputNumber disabled={!healthCheckEnabled} style={{ width: '100%' }} />
      </Form.Item>
    </Card>
  )
}
