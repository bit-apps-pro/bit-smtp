export type LogStoreBody = 'full' | 'redacted' | 'metadata'
export type RetryBackoff = 'exponential' | 'fixed'
export type HealthCheckInterval = 'hourly' | 'twicedaily' | 'daily'

/** Settings tab keys; `#/settings?tab=<key>` deep-links to one. */
export const SETTINGS_TAB_KEYS = [
  'general',
  'reliability',
  'health',
  'notifications',
  'privacy'
] as const
export type SettingsTabKey = (typeof SETTINGS_TAB_KEYS)[number]

export const HEALTH_ALERT_EVENTS = [
  'connection_unhealthy',
  'connection_recovered',
  'oauth_expiring',
  'oauth_expired'
] as const
export type HealthAlertEvent = (typeof HEALTH_ALERT_EVENTS)[number]

/** Every subscribable alert (backend `AlertEvents::keys()`): send failures, then connection health. */
export const ALERT_EVENTS = ['send_failure', ...HEALTH_ALERT_EVENTS] as const
export type AlertEvent = (typeof ALERT_EVENTS)[number]

export const RETRY_FAILURE_CLASSES = ['transient', 'rate_limited'] as const
export type RetryFailureClass = (typeof RETRY_FAILURE_CLASSES)[number]

/** Inclusive [min, max] bounds mirrored from backend `PluginSettings::*_RANGE`. */
export const LOG_RETENTION_DAYS_RANGE = [1, 200] as const
export const SEND_TIMEOUT_SECONDS_RANGE = [5, 120] as const
export const RETRY_MAX_ATTEMPTS_RANGE = [1, 10] as const

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
  notify_events: AlertEvent[]
  uninstall_purge: boolean
  tracking_enabled: boolean
}

export type PreferencesFormValues = Preferences
