export const MASK_SENTINEL = '********'

export interface FieldOption {
  value: string
  label: string
}

export interface FieldDependency {
  field: string
  value: unknown
}

export interface FieldHelpMeta {
  text: string
  url?: string
  linkLabel?: string
}

export interface FieldMeta {
  key: string
  label: string
  type: 'text' | 'email' | 'number' | 'password' | 'select' | 'switch' | 'oauth'
  required: boolean
  secret: boolean
  placeholder: string
  default: unknown
  options: FieldOption[]
  dependsOn: FieldDependency | null
  help?: FieldHelpMeta
  /** Makes the field required while another field holds the given value (e.g. SMTP login when auth is on). */
  requiredWhen?: FieldDependency | null
  /** Editor section the field renders in; `tracking` fields live in the delivery-webhook panel. */
  section?: FieldSection
  /** When this field changes, sets another field to the mapped value if that field still holds one of the mapped defaults. */
  autoSet?: FieldAutoSet
}

export type FieldSection = 'credentials' | 'tracking'

export interface FieldAutoSet {
  field: string
  values: Record<string, string | number | boolean>
}

export interface ProviderMeta {
  key: string
  label: string
  kind: string
  supports_webhook?: boolean
  supports_webhook_provisioning?: boolean
  oauth_redirect_url?: string
  fields: FieldMeta[]
  /** Provider-specific rule for which From addresses are accepted (provider guide registry). */
  fromEmailHint?: string
  /** What a passing test does not prove for this provider, e.g. sandbox limits (provider guide registry). */
  testCaveat?: string
  /** Provider-picker group (provider guide registry). */
  group?: ProviderGroup
  /** One-line "best for" summary shown in the picker (provider guide registry). */
  bestFor?: string
  /** Official setup documentation (provider guide registry). */
  docUrl?: string
  /** Steps to finish in the provider's dashboard before connecting (provider guide registry). */
  prerequisites?: ProviderPrerequisite[]
}

export type ProviderGroup = 'mailbox' | 'transactional' | 'server'

export interface ProviderPrerequisite {
  id: string
  title: string
  body: string
  docUrl?: string
}

export interface ConnectionCredential {
  source: string
  value: string
}

export type WebhookProvisioningStatus = 'registered' | 'failed' | 'unsupported' | 'unavailable'

export interface WebhookProvisioning {
  status: WebhookProvisioningStatus
  reason: string | null
  updated_at: number | null
}

export interface Connection {
  id: string
  provider: string
  kind: string
  name: string
  enabled: boolean
  fromEmail: string
  fromName: string
  replyToEmail: string
  settings: Record<string, unknown>
  credentials: Record<string, ConnectionCredential>
  webhook_url?: string
  webhook_provisioning?: WebhookProvisioning | null
}

export type ConnectionHealthStatus = 'healthy' | 'degraded' | 'unhealthy' | 'unknown'

export interface ConnectionHealth {
  status: ConnectionHealthStatus
  circuit: 'closed' | 'open'
  consecutive_failures: number
  last_ok_at: string | null
  last_error: string | null
  last_probe_at: string | null
  oauth_expires_at: number | null
}

export type ConnectionHealthMap = Record<string, ConnectionHealth>

export type AlertChannelType = 'email' | 'webhook' | 'slack' | 'telegram' | 'discord'

/** Per-type channel settings; each type uses only its own keys (e.g. Slack: webhook_url). */
export interface AlertChannelSettings {
  recipients?: string[]
  url?: string
  signing_secret?: string
  webhook_url?: string
  bot_token?: string
  chat_id?: string
}

export interface AlertChannel {
  id: string
  type: AlertChannelType
  name: string
  enabled: boolean
  settings: AlertChannelSettings
}

export interface FailureAlertSettings {
  enabled: boolean
  reminder_minutes: number
  channels: AlertChannel[]
}

/** Server-computed alert delivery health; read-only, never sent back on save. */
export interface AlertDeliveryStatus {
  cron_disabled: boolean
  overdue: boolean
}

/** The stored alerts blob as the API returns it: the editable settings plus server-owned metadata. */
export interface StoredAlertSettings extends FailureAlertSettings {
  revision?: string
  delivery?: AlertDeliveryStatus
}

export interface MailFeatures extends Record<string, unknown> {
  alerts?: StoredAlertSettings | never[]
}

export interface MailSettings {
  schema_version: number
  enabled: boolean
  default_connection_id: string
  fallback_connection_ids: string[]
  connections: Connection[]
  features: MailFeatures
}
