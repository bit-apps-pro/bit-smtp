import { FAILURE_CLASSES, FAILURE_CLASS_KEYS, type FailureClass } from '@common/glossary'
import { __, sprintf } from '@common/helpers/i18nwrap'
import { type DescribedOption, DescribedOptions, HelpfulFormItem } from '@components/FieldHelp'
import SettingsPanel, { PanelDivider } from '@pages/Settings/components/SettingsPanel'
import RetryQueuePanel from '@pages/Settings/sections/RetryQueuePanel'
import failureTypesHelp from '@pages/Settings/sections/failureTypesHelp'
import {
  type PreferencesFormValues,
  RETRY_MAX_ATTEMPTS_RANGE,
  type RetryBackoff,
  SEND_TIMEOUT_SECONDS_RANGE
} from '@pages/Settings/types'
import { formatList, formatMinutes, retryOffsets } from '@pages/Settings/utils/retrySchedule'
import { Alert, Divider, Form, type FormInstance, InputNumber, Switch, Typography } from 'antd'

const { Text } = Typography

const [TIMEOUT_MIN, TIMEOUT_MAX] = SEND_TIMEOUT_SECONDS_RANGE
const [ATTEMPTS_MIN, ATTEMPTS_MAX] = RETRY_MAX_ATTEMPTS_RANGE

/** All five failure reasons; only the ones worth trying again can be ticked. */
function failureTypeOptions(): DescribedOption<FailureClass>[] {
  return FAILURE_CLASS_KEYS.map(key => {
    const entry = FAILURE_CLASSES[key]
    return {
      value: key,
      label: entry.label,
      description: entry.hint,
      disabled: !entry.retryable,
      disabledReason: entry.next
    }
  })
}

/** Increasing vs fixed wait between tries, each stating its real schedule. */
function backoffOptions(): DescribedOption<RetryBackoff>[] {
  return [
    {
      value: 'exponential',
      label: __('Wait longer each time'),
      description: __('Waits twice as long before each new try, up to 6 hours.')
    },
    {
      value: 'fixed',
      label: __('Same wait every time'),
      description: __('Waits 5 minutes before every try.')
    }
  ]
}

/** One plain sentence saying when the chosen number of tries will happen. */
function schedulePreview(attempts: number | null | undefined, backoff: RetryBackoff | undefined) {
  if (!attempts || attempts < ATTEMPTS_MIN || attempts > ATTEMPTS_MAX) {
    return null
  }
  const times = retryOffsets(attempts, backoff ?? 'exponential').map(formatMinutes)
  return sprintf(__('Tries again about %s after the first failure.'), formatList(times))
}

interface ReliabilityProps {
  form: FormInstance<PreferencesFormValues>
  /** WP-Cron is disabled on this site, so background retries need a real cron job. */
  cronDisabled?: boolean
}

/** Reliability fields (send timeout, automatic retry) plus the live retry-queue panel. */
export default function Reliability({ form, cronDisabled = false }: ReliabilityProps) {
  const retryEnabled = Form.useWatch('retry_enabled', form) ?? false
  const attempts = Form.useWatch('retry_max_attempts', form)
  const backoff = Form.useWatch('retry_backoff', form)
  const preview = schedulePreview(attempts, backoff)

  return (
    <>
      <SettingsPanel
        intro={__(
          'Choose what happens when an email fails. Some problems clear up on their own, so Bit SMTP can try again later.'
        )}
      >
        <HelpfulFormItem
          name="send_timeout_seconds"
          label={__('Send timeout (seconds)')}
          hint={sprintf(
            __('How long to wait for your email provider to answer. Choose %d to %d seconds.'),
            TIMEOUT_MIN,
            TIMEOUT_MAX
          )}
          rules={[
            {
              type: 'number',
              required: true,
              min: TIMEOUT_MIN,
              max: TIMEOUT_MAX,
              message: sprintf(__('Enter %d to %d seconds.'), TIMEOUT_MIN, TIMEOUT_MAX)
            }
          ]}
        >
          <InputNumber style={{ width: '100%' }} />
        </HelpfulFormItem>
        <PanelDivider />
        <HelpfulFormItem
          name="retry_enabled"
          label={__('Try failed emails again')}
          hint={__(
            'Resends emails that failed for a temporary reason, in the background, every few minutes.'
          )}
          valuePropName="checked"
        >
          <Switch />
        </HelpfulFormItem>
        {cronDisabled && (
          <Alert
            type="warning"
            showIcon
            message={__(
              'WordPress background tasks (WP-Cron) are off on this site, so retries only run when your host runs wp-cron.php. Ask your host to set up a cron job.'
            )}
          />
        )}
        <div hidden={!retryEnabled}>
          <PanelDivider />
          <HelpfulFormItem
            name="retry_max_attempts"
            label={__('Number of tries')}
            hint={sprintf(
              __('How many times to try again before giving up. Choose %d to %d.'),
              ATTEMPTS_MIN,
              ATTEMPTS_MAX
            )}
            rules={[
              {
                type: 'number',
                required: retryEnabled,
                min: ATTEMPTS_MIN,
                max: ATTEMPTS_MAX,
                message: sprintf(__('Enter a number from %d to %d.'), ATTEMPTS_MIN, ATTEMPTS_MAX)
              }
            ]}
          >
            <InputNumber style={{ width: '100%' }} />
          </HelpfulFormItem>
          <PanelDivider />
          <HelpfulFormItem
            name="retry_backoff"
            label={__('Wait between tries')}
            hint={preview && <Text type="secondary">{preview}</Text>}
          >
            <DescribedOptions<RetryBackoff>
              type="radio"
              aria-label={__('Wait between tries')}
              options={backoffOptions()}
            />
          </HelpfulFormItem>
          <PanelDivider />
          <HelpfulFormItem
            name="retry_on_classes"
            label={__('Which failures should be tried again?')}
            details={failureTypesHelp()}
            rules={[
              {
                type: 'array',
                required: retryEnabled,
                min: 1,
                message: __('Tick at least one, or turn off “Try failed emails again”.')
              }
            ]}
          >
            <DescribedOptions<FailureClass>
              type="checkbox"
              aria-label={__('Which failures should be tried again?')}
              options={failureTypeOptions()}
            />
          </HelpfulFormItem>
        </div>
      </SettingsPanel>
      <Divider />
      <RetryQueuePanel />
    </>
  )
}
