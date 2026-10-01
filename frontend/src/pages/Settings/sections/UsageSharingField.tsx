import { __ } from '@common/helpers/i18nwrap'
import { HelpfulFormItem } from '@components/FieldHelp'
import {
  SharedDataDetails,
  useSaveTelemetryConsent,
  useTelemetryStatus
} from '@components/TelemetryConsent'
import notify from '@components/Toaster/Toaster'
import { Switch } from 'antd'

/** Usage-data sharing switch; saves right away, separate from the Settings form's Save button. */
export default function UsageSharingField() {
  const { data: status, isPending, isError } = useTelemetryStatus()
  const saveConsent = useSaveTelemetryConsent()

  /** Save the new choice and confirm it with a toast. */
  const handleChange = (allow: boolean) =>
    saveConsent.mutate(allow, {
      onSuccess: () =>
        notify.success(allow ? __('Thanks! Usage sharing is on.') : __('Usage sharing is off.')),
      onError: () => notify.error(__('Couldn’t save your choice. Please try again.'))
    })

  return (
    <HelpfulFormItem
      label={__('Share usage data with the Bit SMTP team')}
      hint={__('Sends basic site facts, never your emails. This switch saves right away.')}
      details={{ title: __('What gets shared?'), body: <SharedDataDetails /> }}
    >
      <Switch
        aria-label={__('Share usage data with the Bit SMTP team')}
        checked={status?.allowed === true}
        disabled={isPending || isError}
        loading={saveConsent.isPending}
        onChange={handleChange}
      />
    </HelpfulFormItem>
  )
}
