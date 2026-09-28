import { type MailSettings, type StoredAlertSettings } from '@pages/Connections/types'

/** Drop server-computed, read-only fields (alerts `delivery`) so a save never posts them back. */
export default function toSavePayload(settings: MailSettings): MailSettings {
  const { alerts } = settings.features
  if (!alerts || Array.isArray(alerts) || !('delivery' in alerts)) {
    return settings
  }

  const writable: StoredAlertSettings = { ...alerts }
  delete writable.delivery

  return { ...settings, features: { ...settings.features, alerts: writable } }
}
