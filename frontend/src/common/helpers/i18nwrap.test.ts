import { afterEach, describe, expect, it, vi } from 'vitest'
import { _n, sprintf } from './i18nwrap'

describe('sprintf', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('fills every placeholder in order when wp-i18n is loaded', () => {
    vi.stubGlobal('wp', { i18n: {} })

    expect(sprintf('%s of %s', '1', '2')).toBe('1 of 2')
  })

  it('fills every placeholder in order without wp-i18n', () => {
    expect(sprintf('%s of %s', '1', '2')).toBe('1 of 2')
  })
})

describe('_n', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('picks the singular or plural form by count', () => {
    expect(_n('%d step', '%d steps', 1)).toBe('%d step')
    expect(_n('%d step', '%d steps', 3)).toBe('%d steps')
  })

  it('prefers the bitapp translations cache for the chosen form', () => {
    vi.stubGlobal('bitapp', { translations: { '%d steps': '%d étapes' } })

    expect(_n('%d step', '%d steps', 2)).toBe('%d étapes')
  })

  it('falls back to the raw form when wp is present without i18n', () => {
    vi.stubGlobal('wp', {})

    expect(_n('%d step', '%d steps', 2)).toBe('%d steps')
  })
})
