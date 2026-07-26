import { type MailSettings, type ProviderMeta } from '@pages/Connections/types'
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
  })

  it('settings fixture satisfies MailSettings and preserves credential/settings maps', () => {
    const s = settingsFixture as MailSettings

    expect(s.schema_version).toBe(2)
    expect(Array.isArray(s.connections)).toBe(true)

    const connection = s.connections[0]
    expect(connection.credentials.api_key.value).toBe('********')
    expect(typeof connection.settings).toBe('object')
  })
})
