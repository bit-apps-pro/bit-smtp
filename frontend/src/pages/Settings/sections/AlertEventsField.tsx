import { __ } from '@common/helpers/i18nwrap'
import { type DescribedOption, DescribedOptions, HelpfulFormItem } from '@components/FieldHelp'
import { ALERT_EVENTS, type AlertEvent, type PreferencesFormValues } from '@pages/Settings/types'
import { Form, type FormInstance, Typography } from 'antd'

const { Text } = Typography

/** Label and one-line meaning for every event an admin can be alerted about. */
function alertEventOptions(): DescribedOption<AlertEvent>[] {
  const copy: Record<AlertEvent, { label: string; description: string }> = {
    send_failure: {
      label: __('Emails failing to send'),
      description: __('One alert when emails start failing, and one when they send again.')
    },
    connection_unhealthy: {
      label: __('A connection stops working'),
      description: __('A connection can’t sign in or send. Check its settings.')
    },
    connection_recovered: {
      label: __('A connection works again'),
      description: __('A connection that had a problem is sending again.')
    },
    oauth_expiring: {
      label: __('Account sign-in about to expire'),
      description: __(
        'A connected account’s sign-in ends within 72 hours. Reconnect it to keep sending.'
      )
    },
    oauth_expired: {
      label: __('Account sign-in expired'),
      description: __('A connected account’s sign-in has ended. Reconnect it to send again.')
    }
  }

  return ALERT_EVENTS.map(event => ({ value: event, ...copy[event] }))
}

/** "Alert me about" checklist bound to the preferences form by instance, since it renders inside the alerts form. */
export default function AlertEventsField({ form }: { form: FormInstance<PreferencesFormValues> }) {
  const selected: AlertEvent[] = Form.useWatch('notify_events', { form, preserve: true }) ?? []
  const label = __('Alert me about')

  return (
    <HelpfulFormItem
      label={label}
      hint={
        selected.length === 0 && (
          <Text type="warning">{__('Nothing is ticked, so no alerts will be sent.')}</Text>
        )
      }
    >
      <DescribedOptions<AlertEvent>
        type="checkbox"
        aria-label={label}
        value={selected}
        onChange={events => form.setFieldValue('notify_events', events)}
        options={alertEventOptions()}
      />
    </HelpfulFormItem>
  )
}
