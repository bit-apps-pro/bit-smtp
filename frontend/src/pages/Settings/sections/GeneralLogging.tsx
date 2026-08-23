import { __ } from '@common/helpers/i18nwrap'
import { type PreferencesFormValues } from '@pages/Settings/types'
import { Card, Form, type FormInstance, InputNumber, Select, Switch, Typography } from 'antd'

const { Text } = Typography

/** General & Logging preference fields: logging toggle, retention window, body storage level. */
export default function GeneralLogging({ form }: { form: FormInstance<PreferencesFormValues> }) {
  const loggingEnabled = Form.useWatch('logging_enabled', form) ?? true

  return (
    <Card title={__('General & Logging')}>
      <Form.Item name="logging_enabled" label={__('Enable logging')} valuePropName="checked">
        <Switch />
      </Form.Item>
      <Form.Item
        name="log_retention_days"
        label={__('Log retention (days)')}
        extra={
          <Text type="secondary">{__('How long to keep log entries before they are purged.')}</Text>
        }
        rules={[
          {
            type: 'number',
            required: true,
            min: 1,
            max: 200,
            message: __('Enter a number of days between 1 and 200')
          }
        ]}
      >
        {/* No min/max prop here: InputNumber would silently clamp on blur, pre-empting the rule above. */}
        <InputNumber disabled={!loggingEnabled} style={{ width: '100%' }} />
      </Form.Item>
      <Form.Item
        name="log_store_body"
        label={__('Message body storage')}
        extra={
          <Text type="secondary">{__('Choose how much of the message body to retain in logs.')}</Text>
        }
      >
        <Select
          disabled={!loggingEnabled}
          options={[
            { value: 'full', label: __('Full body') },
            { value: 'redacted', label: __('Redacted') },
            { value: 'metadata', label: __('Metadata only') }
          ]}
        />
      </Form.Item>
    </Card>
  )
}
