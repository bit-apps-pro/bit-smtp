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
  // only for API connections in the API read shape; never persisted back.
  webhook_url?: string
}

export interface MailSettings {
  schema_version: number
  enabled: boolean
  default_connection_id: string
  fallback_connection_ids: string[]
  connections: Connection[]
  features: Record<string, unknown>
}
