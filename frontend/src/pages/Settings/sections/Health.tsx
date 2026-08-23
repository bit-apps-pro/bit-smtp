import { __ } from '@common/helpers/i18nwrap'
import SettingsPanel, { PanelDivider } from '@pages/Settings/components/SettingsPanel'
import { type PreferencesFormValues } from '@pages/Settings/types'
import { Form, type FormInstance, Select, Switch, Typography } from 'antd'

const { Text } = Typography

/** Health preference fields: periodic connection health checks and their run interval. */
export default function Health({ form }: { form: FormInstance<PreferencesFormValues> }) {
  const healthCheckEnabled = Form.useWatch('health_check_enabled', form) ?? false

  return (
    <SettingsPanel intro={__('Periodically verify each connection can still send mail.')}>
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
          disabled={!healthCheckEnabled}
          options={[
            { value: 'hourly', label: __('Hourly') },
            { value: 'twicedaily', label: __('Twice daily') },
            { value: 'daily', label: __('Daily') }
          ]}
        />
      </Form.Item>
    </SettingsPanel>
  )
}
