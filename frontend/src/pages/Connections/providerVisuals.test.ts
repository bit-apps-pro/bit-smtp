import { describe, expect, it } from 'vitest'
import { getProviderVisual } from './providerVisuals'

describe('getProviderVisual', () => {
  it('resolves a logo, accent and blurb for a known provider', () => {
    const v = getProviderVisual('sendgrid', 'SendGrid')
    expect(v.logo).toBeTruthy()
    expect(v.accent).toBe('#1A82E2')
    expect(v.blurb.length).toBeGreaterThan(0)
    expect(v.initial).toBe('S')
  })
  it('falls back to a letter tile for an unknown provider', () => {
    const v = getProviderVisual('future_provider', 'Future Provider')
    expect(v.logo).toBeNull()
    expect(v.initial).toBe('F')
    expect(v.accent).toBe('#4f46e5')
    expect(v.blurb).toBe('Custom mail provider')
  })
  it('derives the initial from the key when no label is given', () => {
    const v = getProviderVisual('zoho')
    expect(v.initial).toBe('Z')
  })
  it('uses a local-transport visual for PHP Sendmail', () => {
    const v = getProviderVisual('php_sendmail', 'PHP Sendmail')
    expect(v.logo).toBeNull()
    expect(v.initial).toBe('P')
    expect(v.blurb).toBe('PHP mail() / server sendmail')
  })
  it('uses the Cloudflare asset and explains its beta paid-plan availability', () => {
    const v = getProviderVisual('cloudflare', 'Cloudflare')
    expect(v.logo).toBeTruthy()
    expect(v.initial).toBe('C')
    expect(v.accent).toBe('#F6821F')
    expect(v.blurb).toBe('Email Sending beta · paid plan required')
  })
})
