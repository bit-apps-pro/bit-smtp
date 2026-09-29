import { describe, expect, it } from 'vitest'
import { formatList, formatMinutes, retryGaps, retryOffsets } from './retrySchedule'

describe('retrySchedule', () => {
  it('doubles the wait for increasing mode and caps it at six hours', () => {
    expect(retryGaps(9, 'exponential')).toEqual([5, 10, 20, 40, 80, 160, 320, 360, 360])
  })

  it('waits five minutes every time in fixed mode', () => {
    expect(retryGaps(3, 'fixed')).toEqual([5, 5, 5])
  })

  it('adds the waits up into times after the first failure', () => {
    expect(retryOffsets(3, 'exponential')).toEqual([5, 15, 35])
    expect(retryOffsets(3, 'fixed')).toEqual([5, 10, 15])
  })

  it('returns no retries for zero attempts', () => {
    expect(retryOffsets(0, 'exponential')).toEqual([])
  })

  it('formats minutes, whole hours, and mixed durations', () => {
    expect(formatMinutes(35)).toBe('35 min')
    expect(formatMinutes(120)).toBe('2 h')
    expect(formatMinutes(75)).toBe('1 h 15 min')
  })

  it('joins durations into a readable list', () => {
    expect(formatList(['5 min'])).toBe('5 min')
    expect(formatList(['5 min', '15 min', '35 min'])).toBe('5 min, 15 min, and 35 min')
  })
})
