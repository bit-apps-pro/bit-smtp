import { type Connection, type ProviderMeta } from '@pages/Connections/types'
import { describe, expect, it } from 'vitest'
import { hasOAuthToken, isOAuthProvider } from './oauthStatus'

/** A Gmail connection holding the given credentials. */
function withCredentials(credentials: Connection['credentials']): Connection {
  return {
    id: 'conn_1',
    provider: 'gmail',
    kind: 'api',
    name: 'Gmail',
    enabled: true,
    fromEmail: 'a@b.c',
    fromName: '',
    replyToEmail: '',
    settings: {},
    credentials
  }
}

describe('hasOAuthToken', () => {
  it.each(['refresh_token', 'access_token'])('is true when a masked %s is stored', key => {
    expect(hasOAuthToken(withCredentials({ [key]: { source: 'database', value: '********' } }))).toBe(
      true
    )
  })

  it('is false with only client credentials or an empty token', () => {
    expect(
      hasOAuthToken(withCredentials({ client_secret: { source: 'database', value: '********' } }))
    ).toBe(false)
    expect(hasOAuthToken(withCredentials({ refresh_token: { source: 'database', value: '' } }))).toBe(
      false
    )
  })
})

describe('isOAuthProvider', () => {
  const provider = (types: ProviderMeta['fields'][number]['type'][]): ProviderMeta => ({
    key: 'p',
    label: 'P',
    kind: 'api',
    fields: types.map(type => ({
      key: type,
      label: type,
      type,
      required: false,
      secret: false,
      placeholder: '',
      default: '',
      options: [],
      dependsOn: null
    }))
  })

  it('is true only when the provider has an oauth field', () => {
    expect(isOAuthProvider(provider(['text', 'oauth']))).toBe(true)
    expect(isOAuthProvider(provider(['text', 'password']))).toBe(false)
  })
})
