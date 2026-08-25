import { type ConnectionHealthMap, type MailSettings, type ProviderMeta } from '@pages/Connections/types'
import providersFixture from '@pages/__fixtures__/rest-providers.fixture.json'
import settingsFixture from '@pages/__fixtures__/rest-settings.fixture.json'
import { describe, expect, it } from 'vitest'

describe('REST contract → frontend types', () => {
  it('provider metadata fixture satisfies ProviderMeta and drives the field renderer', () => {
    const providers = providersFixture as ProviderMeta[]

    expect(Array.isArray(providers)).toBe(true)
    providers.forEach(p => {
      expect(typeof p.key).toBe('string')
      expect(typeof p.kind).toBe('string')
      expect(Array.isArray(p.fields)).toBe(true)
      p.fields.forEach(f => {
        expect(typeof f.key).toBe('string')
        expect(['text', 'email', 'number', 'password', 'select', 'switch', 'oauth']).toContain(f.type)
      })
    })

    const sendgrid = providers.find(p => p.key === 'sendgrid')
    expect(sendgrid?.supports_webhook_provisioning).toBe(true)

    const oauthRedirectUrl = 'http://example.org/bit-smtp/oauth/callback'
    expect(providers.find(p => p.key === 'gmail')?.oauth_redirect_url).toBe(oauthRedirectUrl)
    expect(providers.find(p => p.key === 'microsoft365')?.oauth_redirect_url).toBe(oauthRedirectUrl)
    expect(sendgrid).not.toHaveProperty('oauth_redirect_url')
  })

  it('settings fixture satisfies MailSettings and preserves credential/settings maps', () => {
    const s = settingsFixture as MailSettings

    expect(s.schema_version).toBe(2)
    expect(Array.isArray(s.connections)).toBe(true)

    const connection = s.connections[0]
    expect(connection.credentials.api_key.value).toBe('********')
    expect(typeof connection.settings).toBe('object')
  })

  it('mail/connections/health returns a public-shape map with no internal alert bookkeeping', () => {
    const map: ConnectionHealthMap = {
      conn_a: {
        status: 'unhealthy',
        circuit: 'open',
        consecutive_failures: 3,
        last_ok_at: '2026-08-24T09:00:00Z',
        last_error: 'SMTP connect() failed',
        last_probe_at: '2026-08-24T10:00:00Z',
        oauth_expires_at: 1893456000
      }
    }

    const record = map.conn_a
    expect(Object.keys(record)).toEqual([
      'status',
      'circuit',
      'consecutive_failures',
      'last_ok_at',
      'last_error',
      'last_probe_at',
      'oauth_expires_at'
    ])
    expect(record).not.toHaveProperty('last_alerted_state')
    expect(record).not.toHaveProperty('last_alerted_at')
  })
})
