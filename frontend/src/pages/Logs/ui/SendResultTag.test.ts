import { describe, expect, it } from 'vitest'
import { failedAttemptCount, sendResultOf } from './SendResultTag'

const details = (statuses: Array<'sent' | 'accepted' | 'failed'>) => ({
  message: '',
  headers: {},
  attachments: [],
  attempts: statuses.map(status => ({ connection: 'c', status }))
})

describe('sendResultOf', () => {
  it('classifies a zero status as failed', () => {
    expect(sendResultOf({ status: '0', details: details(['failed']) })).toBe('failed')
  })

  it('classifies a single successful attempt as sent', () => {
    expect(sendResultOf({ status: '1', details: details(['sent']) })).toBe('sent')
  })

  it('classifies a success after earlier failed connections as sent via failover', () => {
    const log = { status: '1', details: details(['failed', 'failed', 'accepted']) }

    expect(sendResultOf(log)).toBe('failover_recovered')
    expect(failedAttemptCount(log)).toBe(2)
  })
})
