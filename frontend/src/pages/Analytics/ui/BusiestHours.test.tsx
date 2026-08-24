import { type BusyTimeSlot } from '@pages/Analytics/types'
import { describe, expect, it } from 'vitest'
import { buildHourMetrics } from './BusiestHours'

describe('buildHourMetrics', () => {
  it('lists the hour label and its total sends', () => {
    const slot: BusyTimeSlot = { hour: 14, label: '14:00', total: 42 }

    expect(buildHourMetrics(slot)).toEqual([
      { label: 'Hour', value: '14:00' },
      { label: 'Sends', value: '42' }
    ])
  })
})
