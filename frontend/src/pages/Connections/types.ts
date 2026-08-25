// Mirrors the backend MailSettingsSerializer::MASK_SENTINEL.
export const MASK_SENTINEL = '********'

export interface FieldOption {
  value: string
  label: string
}

export interface FieldDependency {
  field: string
  value: unknown
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
}

export interface ProviderMeta {
  key: string
  label: string
  kind: string
  supports_webhook?: boolean
  // Provider can create its own webhook via API (drives the "Create webhook" button).
  supports_webhook_provisioning?: boolean
  // Exact callback URL users must register with an OAuth2 provider.
  oauth_redirect_url?: string
  fields: FieldMeta[]
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
  // Derived, read-only: the full delivery-webhook URL to paste into the provider dashboard. Present
  // only for providers with a live webhook receiver; never persisted back.
  webhook_url?: string
  // Derived, read-only: outcome of the most recent auto-provision attempt for the URL above. `null`
  // once provisioning was never attempted; absent for providers without webhook support at all.
  webhook_provisioning?: WebhookProvisioning | null
}

export type ConnectionHealthStatus = 'healthy' | 'degraded' | 'unhealthy' | 'unknown'

// Public, secret-free health projection returned by the mail/connections/health endpoint. Mirrors
// ConnectionHealth::toPublicArray() — never carries the backend's internal alert bookkeeping.
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

export interface FailureAlertSettings {
  enabled: boolean
  email: {
    enabled: boolean
    recipients: string[]
  }
  webhook: {
    enabled: boolean
    url: string
    signing_secret: string
  }
  slack: {
    enabled: boolean
    webhook_url: string
  }
  telegram: {
    enabled: boolean
    bot_token: string
    chat_id: string
  }
}

export interface MailFeatures extends Record<string, unknown> {
  // Backend sanitizer emits `[]` (PHP's empty-array sentinel) until alerts are configured, then the full shape.
  alerts?: FailureAlertSettings | never[]
}

export interface MailSettings {
  schema_version: number
  enabled: boolean
  default_connection_id: string
  fallback_connection_ids: string[]
  connections: Connection[]
  features: MailFeatures
}
