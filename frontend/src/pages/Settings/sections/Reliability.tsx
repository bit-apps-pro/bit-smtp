import { __ } from '@common/helpers/i18nwrap'
import SettingsPanel, { PanelDivider } from '@pages/Settings/components/SettingsPanel'
import RetryQueuePanel from '@pages/Settings/sections/RetryQueuePanel'
import {
  type PreferencesFormValues,
  RETRY_FAILURE_CLASSES,
  type RetryFailureClass
} from '@pages/Settings/types'
import { Divider, Form, type FormInstance, InputNumber, Select, Switch, Typography } from 'antd'

const { Text } = Typography

/** Reliability preference fields (send timeout, retry behavior) plus the live retry-queue panel. */
export default function Reliability({ form }: { form: FormInstance<PreferencesFormValues> }) {
  const retryEnabled = Form.useWatch('retry_enabled', form) ?? false

  const retryClassLabels: Record<RetryFailureClass, string> = {
    transient: __('Transient errors'),
    rate_limited: __('Rate limited')
  }

  return (
    <>
      <SettingsPanel
        intro={__(
          'Tune how long BitSMTP waits for a provider to respond, and whether failed sends retry automatically.'
        )}
      >
        <Form.Item
          name="send_timeout_seconds"
          label={__('Send timeout (seconds)')}
          extra={
            <Text type="secondary">
              {__('Maximum time to wait for a provider to accept a message.')}
            </Text>
          }
          rules={[{ type: 'number', required: true, min: 1, message: __('Enter at least 1 second') }]}
        >
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
        <PanelDivider />
        <Form.Item
          name="retry_on_classes"
          label={__('Retry only these failure types')}
          extra={
            <Text type="secondary">
              {__('Leave empty to retry every retryable failure. Select types to retry only those.')}
            </Text>
          }
        >
          <Select
            mode="multiple"
            allowClear
            disabled={!retryEnabled}
            placeholder={__('All retryable failures')}
            options={RETRY_FAILURE_CLASSES.map(failureClass => ({
              value: failureClass,
              label: retryClassLabels[failureClass]
            }))}
          />
        </Form.Item>
      </SettingsPanel>
      <Divider />
      <RetryQueuePanel />
    </>
  )
}
