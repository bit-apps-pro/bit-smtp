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
}

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
