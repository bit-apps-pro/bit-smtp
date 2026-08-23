import { __ } from '@common/helpers/i18nwrap'
import SettingsPanel, { PanelDivider } from '@pages/Settings/components/SettingsPanel'
import { type PreferencesFormValues } from '@pages/Settings/types'
import { Form, type FormInstance, InputNumber, Select, Switch, Typography } from 'antd'

const { Text } = Typography

/** Reliability preference fields: send timeout and automatic retry behavior. */
export default function Reliability({ form }: { form: FormInstance<PreferencesFormValues> }) {
  const retryEnabled = Form.useWatch('retry_enabled', form) ?? false

  return (
    <SettingsPanel
      intro={__(
        'Tune how long BitSMTP waits for a provider to respond, and whether failed sends retry automatically.'
      )}
    >
      <Form.Item
        name="send_timeout_seconds"
        label={__('Send timeout (seconds)')}
        extra={
          <Text type="secondary">{__('Maximum time to wait for a provider to accept a message.')}</Text>
        }
        rules={[{ type: 'number', required: true, min: 1, message: __('Enter at least 1 second') }]}
      >
        {/* No min prop here: InputNumber would silently clamp on blur, pre-empting the rule above. */}
        <InputNumber style={{ width: '100%' }} />
      </Form.Item>
      <PanelDivider />
      <Form.Item name="retry_enabled" label={__('Enable automatic retry')} valuePropName="checked">
        <Switch />
      </Form.Item>
      <PanelDivider />
      <Form.Item
        name="retry_max_attempts"
        label={__('Max retry attempts')}
        rules={[{ type: 'number', required: true, min: 1, message: __('Enter at least 1 attempt') }]}
      >
        <InputNumber disabled={!retryEnabled} style={{ width: '100%' }} />
      </Form.Item>
      <PanelDivider />
      <Form.Item name="retry_backoff" label={__('Retry backoff strategy')}>
        <Select
          disabled={!retryEnabled}
          options={[
            { value: 'exponential', label: __('Exponential') },
            { value: 'fixed', label: __('Fixed') }
          ]}
        />
      </Form.Item>
    </SettingsPanel>
  )
}
