import { clampedPresets, retentionFloor } from '@pages/Analytics/AnalyticsFilters'
import dayjs from 'dayjs'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

describe('retentionFloor / clampedPresets', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-02-15T12:34:56.000Z'))
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('computes the earliest selectable start-of-day as retentionDays - 1 back', () => {
    expect(retentionFloor(7).toISOString()).toBe(dayjs().subtract(6, 'day').startOf('day').toISOString())
  })

  it('clamps the "Last 90 days" preset start up to the retention floor on a short-retention site', () => {
    const presets = clampedPresets(7)
    const last90 = presets.find(preset => preset.label === 'Last 90 days')

    expect(last90?.value[0].toISOString()).toBe(retentionFloor(7).toISOString())
  })

  it('leaves the "Last 90 days" preset start unclamped on a generously long-retention site', () => {
    const presets = clampedPresets(120)
    const last90 = presets.find(preset => preset.label === 'Last 90 days')

    expect(last90?.value[0].toISOString()).toBe(dayjs().subtract(90, 'day').toISOString())
  })
})
