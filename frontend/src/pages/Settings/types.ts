export type LogStoreBody = 'full' | 'redacted' | 'metadata'
export type RetryBackoff = 'exponential' | 'fixed'
export type HealthCheckInterval = 'hourly' | 'twicedaily' | 'daily'

// Mirrors HealthNotification::EVENT_* (backend/app/Mail/Notifications/HealthNotification.php): the
// canonical, ordered list of subscribable health/OAuth alert events and the union derived from it —
// one source the Health control renders from and every consumer types against.
export const HEALTH_ALERT_EVENTS = [
  'connection_unhealthy',
  'connection_recovered',
  'oauth_expiring',
  'oauth_expired'
] as const
export type HealthAlertEvent = (typeof HEALTH_ALERT_EVENTS)[number]

// Mirrors PluginSettings::schema() (backend/app/Settings/PluginSettings.php): the four preference
// groups (general/reliability/health/privacy) flattened into one key/value shape.
export interface Preferences {
  logging_enabled: boolean
  log_retention_days: number
  log_store_body: LogStoreBody
  send_timeout_seconds: number
  retry_enabled: boolean
  retry_max_attempts: number
  retry_backoff: RetryBackoff
  // Not editable in the UI yet; carried through save/import untouched.
  retry_on_classes: string[]
  health_check_enabled: boolean
  health_check_interval: HealthCheckInterval
  notify_cooldown_minutes: number
  notify_events: HealthAlertEvent[]
  uninstall_purge: boolean
  tracking_enabled: boolean
}

// The Form only registers Form.Item-bound fields; retry_on_classes is excluded here and re-attached
// from the last-loaded preferences before the save request is sent.
export type PreferencesFormValues = Omit<Preferences, 'retry_on_classes'>
