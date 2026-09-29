import { describe, expect, it } from 'vitest'
import {
  DELIVERY_STATUSES,
  DELIVERY_STATUS_KEYS,
  FAILURE_CLASSES,
  FAILURE_CLASS_KEYS,
  HEALTH_STATUSES,
  deliveryStatusEntry,
  failureClassEntry,
  healthStatusEntry,
  sendResultEntry
} from './glossary'

describe('glossary', () => {
  it('marks only transient and rate-limited failures as retryable, matching FailureCategory::RETRYABLE', () => {
    const retryable = FAILURE_CLASS_KEYS.filter(key => FAILURE_CLASSES[key].retryable)

    expect(retryable).toEqual(['transient', 'rate_limited'])
  })

  it('has an entry for every declared key', () => {
    expect(Object.keys(FAILURE_CLASSES)).toEqual([...FAILURE_CLASS_KEYS])
    expect(Object.keys(DELIVERY_STATUSES)).toEqual([...DELIVERY_STATUS_KEYS])
  })

  it('returns known entries unchanged', () => {
    expect(failureClassEntry('auth')).toBe(FAILURE_CLASSES.auth)
    expect(deliveryStatusEntry('spam').label).toBe('Marked as spam')
    expect(sendResultEntry('failover_recovered').label).toBe('Sent via failover')
    expect(healthStatusEntry('unhealthy')).toBe(HEALTH_STATUSES.unhealthy)
  })

  it('falls back to the raw key as label for unknown values', () => {
    const failure = failureClassEntry('smtp_weird')
    expect(failure.label).toBe('smtp_weird')
    expect(failure.retryable).toBe(false)
    expect(deliveryStatusEntry('opened').label).toBe('opened')
    expect(sendResultEntry('queued').label).toBe('queued')
  })

  it('labels empty values as Unknown and ignores inherited object keys', () => {
    expect(failureClassEntry(null).label).toBe('Unknown')
    expect(deliveryStatusEntry(undefined).label).toBe('Unknown')
    expect(deliveryStatusEntry('toString').label).toBe('toString')
  })

  it('treats an unknown health status as not checked yet', () => {
    expect(healthStatusEntry('exploded')).toBe(HEALTH_STATUSES.unknown)
    expect(healthStatusEntry(null).label).toBe('Not checked yet')
  })
})
