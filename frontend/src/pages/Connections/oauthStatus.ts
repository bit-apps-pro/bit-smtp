import { type Connection, type ProviderMeta } from '@pages/Connections/types'

/** Credential keys that hold OAuth tokens, matching the backend's ConnectionAuthorization::OAUTH_TOKEN_KEYS. */
const OAUTH_TOKEN_KEYS = ['refresh_token', 'access_token'] as const

/** Whether a stored connection holds an OAuth token (sign-in finished); the API masks the value but only sends the key while a token is stored. */
export function hasOAuthToken(connection: Connection): boolean {
  return OAUTH_TOKEN_KEYS.some(key => Boolean(connection.credentials?.[key]?.value))
}

/** Whether a provider connects through an OAuth sign-in instead of typed-in credentials. */
export function isOAuthProvider(provider: ProviderMeta): boolean {
  return provider.fields.some(field => field.type === 'oauth')
}
