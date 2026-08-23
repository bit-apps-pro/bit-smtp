export type LogStoreBody = 'full' | 'redacted' | 'metadata'
export type RetryBackoff = 'exponential' | 'fixed'
export type HealthCheckInterval = 'hourly' | 'twicedaily' | 'daily'

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
  // Not editable in the UI yet; carried through save/import untouched.
  notify_events: string[]
  uninstall_purge: boolean
  tracking_enabled: boolean
}

// The Form only registers Form.Item-bound fields; retry_on_classes/notify_events are excluded here
// and re-attached from the last-loaded preferences before the save request is sent.
export type PreferencesFormValues = Omit<Preferences, 'retry_on_classes' | 'notify_events'>
