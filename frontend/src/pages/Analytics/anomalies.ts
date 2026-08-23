import { __ } from '@common/helpers/i18nwrap'
import { formatExactNumber, formatSignedPercent, formatSignedPoints } from '@pages/Analytics/format'
import { type StatusKey } from '@pages/Analytics/palette'
import { type AnomalyObservation } from '@pages/Analytics/types'

const WEEKDAY_LABELS = [
  __('Monday'),
  __('Tuesday'),
  __('Wednesday'),
  __('Thursday'),
  __('Friday'),
  __('Saturday'),
  __('Sunday')
]

export type Direction = 'up' | 'down'

/** A rendering-ready description of one anomaly observation: label, detail, direction, and severity. */
export interface ObservationDescription {
  label: string
  detail: string
  direction: Direction
  /** `null` = informational only, no status color/icon (the shift isn't inherently good or bad). */
  severity: StatusKey | null
}

/** Failure-rate severity: worse is bad, better is good, and a big jump escalates to critical. */
function failureRateSeverity(pointChange: number): StatusKey {
  if (pointChange <= 0) return 'good'
  return pointChange >= 10 ? 'critical' : 'warning'
}

/** Translate one backend anomaly observation into label/detail/direction/severity for the card UI. */
export function describeObservation(observation: AnomalyObservation): ObservationDescription {
  switch (observation.type) {
    case 'volume_change':
      return {
        label: `${__('Send volume')} ${formatSignedPercent(observation.percentage_change)}`,
        detail: `${formatExactNumber(observation.current)} ${__('vs')} ${formatExactNumber(
          observation.prior
        )} ${__('in the prior period')}`,
        direction: observation.percentage_change >= 0 ? 'up' : 'down',
        severity: null
      }
    case 'failure_rate_change':
      return {
        label: `${__('Failure rate')} ${formatSignedPoints(observation.percentage_point_change)}`,
        detail: `${observation.current_failure_rate}% ${__('now vs')} ${
          observation.prior_failure_rate
        }% ${__('prior')}`,
        direction: observation.percentage_point_change >= 0 ? 'up' : 'down',
        severity: failureRateSeverity(observation.percentage_point_change)
      }
    case 'connection_failure_rate_change':
      return {
        label: `${observation.connection} ${__('failure rate')} ${formatSignedPoints(
          observation.percentage_point_change
        )}`,
        detail: `${observation.current_failure_rate}% ${__('now vs')} ${
          observation.prior_failure_rate
        }% ${__('prior')}`,
        direction: observation.percentage_point_change >= 0 ? 'up' : 'down',
        severity: failureRateSeverity(observation.percentage_point_change)
      }
    case 'newly_active_source':
      return {
        label: `${__('New source')}: ${observation.source}`,
        detail: `${formatExactNumber(observation.total)} ${__(
          'sends this period, none in the prior period'
        )}`,
        direction: 'up',
        severity: null
      }
    case 'inactive_source':
      return {
        label: `${__('Source went quiet')}: ${observation.source}`,
        detail: `${formatExactNumber(observation.total)} ${__(
          'sends in the prior period, none this period'
        )}`,
        direction: 'down',
        severity: null
      }
    case 'hourly_distribution_shift':
      return {
        label: `${String(observation.hour).padStart(2, '0')}:00 ${__('share')} ${formatSignedPoints(
          observation.percentage_point_change
        )}`,
        detail: `${observation.current_percentage}% ${__('now vs')} ${
          observation.prior_percentage
        }% ${__('prior')}`,
        direction: observation.percentage_point_change >= 0 ? 'up' : 'down',
        severity: null
      }
    case 'weekday_distribution_shift':
      return {
        label: `${WEEKDAY_LABELS[observation.weekday - 1] ?? observation.weekday} ${__(
          'share'
        )} ${formatSignedPoints(observation.percentage_point_change)}`,
        detail: `${observation.current_percentage}% ${__('now vs')} ${
          observation.prior_percentage
        }% ${__('prior')}`,
        direction: observation.percentage_point_change >= 0 ? 'up' : 'down',
        severity: null
      }
    default:
      return { label: __('Unrecognized observation'), detail: '', direction: 'up', severity: null }
  }
}
