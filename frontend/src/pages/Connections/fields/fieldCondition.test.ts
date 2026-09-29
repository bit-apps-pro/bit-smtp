import { describe, expect, it } from 'vitest'
import conditionHolds from './fieldCondition'

describe('conditionHolds', () => {
  it('compares boolean conditions by truthiness', () => {
    expect(conditionHolds(1, { field: 'auth', value: true })).toBe(true)
    expect(conditionHolds('1', { field: 'auth', value: true })).toBe(true)
    expect(conditionHolds(undefined, { field: 'auth', value: true })).toBe(false)
    expect(conditionHolds(0, { field: 'auth', value: false })).toBe(true)
  })

  it('compares any other condition strictly', () => {
    expect(conditionHolds('custom', { field: 'mode', value: 'custom' })).toBe(true)
    expect(conditionHolds('Custom', { field: 'mode', value: 'custom' })).toBe(false)
    expect(conditionHolds('1', { field: 'port', value: 1 })).toBe(false)
  })
})
