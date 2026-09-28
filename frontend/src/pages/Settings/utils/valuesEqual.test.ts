import { describe, expect, it } from 'vitest'
import valuesEqual from './valuesEqual'

describe('valuesEqual', () => {
  it('treats structurally equal nested arrays of objects as equal despite different identities', () => {
    const loaded = { channels: [{ id: 'ch_a1', settings: { recipients: ['a@example.test'] } }] }
    const live = { channels: [{ id: 'ch_a1', settings: { recipients: ['a@example.test'] } }] }

    expect(valuesEqual(live, loaded)).toBe(true)
  })

  it('detects a changed value deep inside a nested object', () => {
    const loaded = { channels: [{ id: 'ch_a1', settings: { webhook_url: 'x' } }] }
    const live = { channels: [{ id: 'ch_a1', settings: { webhook_url: 'y' } }] }

    expect(valuesEqual(live, loaded)).toBe(false)
  })

  it('detects an added nested key', () => {
    expect(
      valuesEqual({ channels: [{ id: 'ch_a1', name: 'Ops' }] }, { channels: [{ id: 'ch_a1' }] })
    ).toBe(false)
  })

  it('ignores loaded keys the live snapshot does not carry yet', () => {
    expect(valuesEqual({ enabled: true }, { enabled: true, channels: [] })).toBe(true)
  })
})
