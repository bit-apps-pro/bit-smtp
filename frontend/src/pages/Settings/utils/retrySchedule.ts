import { __, sprintf } from '@common/helpers/i18nwrap'
import { type RetryBackoff } from '@pages/Settings/types'

/** Mirrors backend `RetryWorker::BASE_SECONDS` (5 min) and `CAP_SECONDS` (6 h), in minutes. */
export const RETRY_BASE_MINUTES = 5
export const RETRY_CAP_MINUTES = 360

const MINUTES_PER_HOUR = 60

/** Minutes waited before each retry, ignoring the backend's small random jitter. */
export function retryGaps(attempts: number, backoff: RetryBackoff): number[] {
  return Array.from({ length: Math.max(0, attempts) }, (_, index) =>
    backoff === 'fixed'
      ? RETRY_BASE_MINUTES
      : Math.min(RETRY_CAP_MINUTES, RETRY_BASE_MINUTES * 2 ** index)
  )
}

/** Minutes after the first failure at which each retry runs. */
export function retryOffsets(attempts: number, backoff: RetryBackoff): number[] {
  let elapsed = 0
  return retryGaps(attempts, backoff).map(gap => {
    elapsed += gap
    return elapsed
  })
}

/** A short human duration such as "5 min", "2 h" or "1 h 15 min". */
export function formatMinutes(minutes: number): string {
  const hours = Math.floor(minutes / MINUTES_PER_HOUR)
  const rest = minutes % MINUTES_PER_HOUR
  if (hours === 0) {
    return sprintf(__('%d min'), rest)
  }
  return rest === 0 ? sprintf(__('%d h'), hours) : sprintf(__('%d h %d min'), hours, rest)
}

/** Joins durations as a readable list in the page language, e.g. "5 min, 15 min and 35 min". */
export function formatList(items: string[]): string {
  const locale = document.documentElement.lang || undefined
  return new Intl.ListFormat(locale, { style: 'long', type: 'conjunction' }).format(items)
}
