import { __ } from '@common/helpers/i18nwrap'
import { HelpfulFormItem } from '@components/FieldHelp'
import SettingsPanel, { PanelDivider } from '@pages/Settings/components/SettingsPanel'
import { type HealthCheckInterval, type PreferencesFormValues } from '@pages/Settings/types'
import { Alert, Button, Form, type FormInstance, Select, Switch } from 'antd'

interface HealthProps {
  form: FormInstance<PreferencesFormValues>
  /** Alerts are switched on and at least one channel is enabled. */
  alertsReady: boolean
  /** At least one connection event is ticked under Notifications › Alert me about. */
  healthAlertsSelected: boolean
  onOpenNotifications: () => void
}

/** Why a check result would reach nobody, or null when health alerts can be delivered. */
function silentAlertsReason(alertsReady: boolean, healthAlertsSelected: boolean): string | null {
  if (!alertsReady) {
    return __('Alerts are off or have no channel. Turn on “Send alerts” and add a channel.')
  }
  if (!healthAlertsSelected) {
    return __('No connection events are ticked under “Alert me about”.')
  }
  return null
}

/** Health fields: the background check switch and schedule, plus a warning when nobody would be alerted. */
export default function Health({
  form,
  alertsReady,
  healthAlertsSelected,
  onOpenNotifications
}: HealthProps) {
  const healthCheckEnabled = Form.useWatch('health_check_enabled', form) ?? false
  const silentReason = silentAlertsReason(alertsReady, healthAlertsSelected)
  const intervalOptions: { value: HealthCheckInterval; label: string }[] = [
    { value: 'hourly', label: __('Every hour') },
    { value: 'twicedaily', label: __('Twice a day') },
    { value: 'daily', label: __('Once a day') }
  ]

  return (
    <SettingsPanel
      intro={__(
        'Bit SMTP can check in the background that each connection still works, so you hear about problems early.'
      )}
    >
      <HelpfulFormItem
        name="health_check_enabled"
        label={__('Check connections automatically')}
        hint={__('Each check only signs in to your provider. No email is sent, so it uses no quota.')}
        valuePropName="checked"
      >
        <Switch />
      </HelpfulFormItem>
      <div hidden={!healthCheckEnabled}>
        <PanelDivider />
        <HelpfulFormItem name="health_check_interval" label={__('How often to check')}>
          <Select options={intervalOptions} style={{ maxWidth: 240 }} />
        </HelpfulFormItem>
      </div>
      <PanelDivider />
      {healthCheckEnabled && silentReason ? (
        <Alert
          type="warning"
          showIcon
          message={__('You won’t be alerted about connection problems')}
          description={silentReason}
          action={
            <Button size="small" onClick={onOpenNotifications}>
              {__('Set up alerts')}
            </Button>
          }
        />
      ) : (
        <Button
          type="link"
          onClick={onOpenNotifications}
          style={{ paddingInline: 0, alignSelf: 'flex-start' }}
        >
          {__('Choose which problems send an alert')}
        </Button>
      )}
    </SettingsPanel>
  )
}
