export type LogStoreBody = 'full' | 'redacted' | 'metadata'
export type RetryBackoff = 'exponential' | 'fixed'
export type HealthCheckInterval = 'hourly' | 'twicedaily' | 'daily'

export const HEALTH_ALERT_EVENTS = [
  'connection_unhealthy',
  'connection_recovered',
  'oauth_expiring',
  'oauth_expired'
] as const
export type HealthAlertEvent = (typeof HEALTH_ALERT_EVENTS)[number]

export const RETRY_FAILURE_CLASSES = ['transient', 'rate_limited'] as const
export type RetryFailureClass = (typeof RETRY_FAILURE_CLASSES)[number]

export interface Preferences {
  logging_enabled: boolean
  log_retention_days: number
  log_store_body: LogStoreBody
  send_timeout_seconds: number
  retry_enabled: boolean
  retry_max_attempts: number
  retry_backoff: RetryBackoff
  retry_on_classes: RetryFailureClass[]
  health_check_enabled: boolean
  health_check_interval: HealthCheckInterval
  notify_cooldown_minutes: number
  notify_events: HealthAlertEvent[]
  uninstall_purge: boolean
  tracking_enabled: boolean
}

export type PreferencesFormValues = Preferences
