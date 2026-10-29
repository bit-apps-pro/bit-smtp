import { __, sprintf } from '@common/helpers/i18nwrap'
import { type DescribedOption, DescribedOptions, HelpfulFormItem } from '@components/FieldHelp'
import SettingsPanel, { PanelDivider } from '@pages/Settings/components/SettingsPanel'
import {
  LOG_RETENTION_DAYS_RANGE,
  type LogStoreBody,
  type PreferencesFormValues
} from '@pages/Settings/types'
import { Form, type FormInstance, InputNumber, Switch } from 'antd'

const [RETENTION_MIN, RETENTION_MAX] = LOG_RETENTION_DAYS_RANGE

/** How much of each email the log keeps, stating what is lost with the privacy-friendly modes. */
function bodyStorageOptions(): DescribedOption<LogStoreBody>[] {
  return [
    {
      value: 'full',
      label: __('Full message'),
      description: __('Keeps the whole email, so you can read it and resend it later.')
    },
    {
      value: 'redacted',
      label: __('Hide the message text'),
      description: __(
        'Keeps subject and recipients, and replaces the text with “[redacted]”. Can’t be resent.'
      )
    },
    {
      value: 'metadata',
      label: __('Details only'),
      description: __('Keeps subject, recipients and headers, with no message text. Can’t be resent.')
    }
  ]
}

/** General & Logging fields: logging toggle, retention window, and how much of each email to keep. */
export default function GeneralLogging({ form }: { form: FormInstance<PreferencesFormValues> }) {
  const loggingEnabled = Form.useWatch('logging_enabled', form) ?? true

  return (
    <SettingsPanel
      intro={__('Keep a record of the emails your site sends, so you can check or resend them.')}
    >
      <HelpfulFormItem
        name="logging_enabled"
        label={__('Keep email logs')}
        hint={__('Turn off to stop saving new emails to the log.')}
        valuePropName="checked"
      >
        <Switch />
      </HelpfulFormItem>
      <div hidden={!loggingEnabled}>
        <PanelDivider />
        <HelpfulFormItem
          name="log_retention_days"
          label={__('Keep logs for (days)')}
          hint={sprintf(
            __('Older logs are deleted automatically. Choose %1$d to %2$d days.'),
            RETENTION_MIN,
            RETENTION_MAX
          )}
          rules={[
            {
              type: 'number',
              required: true,
              min: RETENTION_MIN,
              max: RETENTION_MAX,
              message: sprintf(__('Enter %1$d to %2$d days.'), RETENTION_MIN, RETENTION_MAX)
            }
          ]}
        >
          <InputNumber style={{ width: '100%' }} />
        </HelpfulFormItem>
        <PanelDivider />
        <HelpfulFormItem name="log_store_body" label={__('What to keep from each email')}>
          <DescribedOptions<LogStoreBody>
            type="radio"
            aria-label={__('What to keep from each email')}
            options={bodyStorageOptions()}
          />
        </HelpfulFormItem>
      </div>
    </SettingsPanel>
  )
}
