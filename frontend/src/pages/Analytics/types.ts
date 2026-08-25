/** Shared TypeScript shapes for the bit-smtp/v1 analytics REST payloads (see MailAnalyticsService). */

export type AnalyticsBucket = 'hour' | 'day' | 'week'

/** Query params accepted by all three analytics endpoints; forwarded to `request()` as query string. */
export interface AnalyticsRangeParams {
  start: string
  end: string
  bucket?: AnalyticsBucket
  plugin?: string
  connectionId?: string
}

export interface AnalyticsRange {
  start: string
  end: string
}

export interface AcceptanceStats {
  accepted: number
  failed: number
  denominator: number
  accepted_rate: number
}

export interface DeliveryStats {
  delivered: number
  delayed: number
  bounced: number
  blocked: number
  spam: number
  accepted: number
  pending: number
  unknown: number
  denominator: number
  delivered_rate: number
}

export interface SeriesBucket {
  bucket: string
  label: string
  total: number
  accepted: number
  failed: number
  delivered: number
  verified_delivery: number
}

export interface DimensionGroup {
  dimension: string
  total: number
  accepted: number
  failed: number
  delivered: number
  verified_delivery: number
}

export interface BusyTimeSlot {
  hour: number
  label: string
  total: number
}

export interface RetainedRecords {
  earliest: string | null
  latest: string | null
}

export interface TimestampCoverage {
  qualified_records: number
  unqualified_records: number
  interpretation: string
}

/** `GET analytics/overview` response body (MailAnalyticsService::computeOverview). */
export interface Overview {
  range: AnalyticsRange
  timezone: string
  total: number
  unknown_source_count: number
  unknown_recipient_count: number
  interpretation: string
  logging_enabled: boolean
  retained_records: RetainedRecords
  timestamp_coverage: TimestampCoverage
  recipients: number
  acceptance: AcceptanceStats
  delivery: DeliveryStats
  busiest_hours: Array<BusyTimeSlot>
  series: Array<SeriesBucket>
  top_sources: Array<DimensionGroup>
  top_connections: Array<DimensionGroup>
}

/** `GET analytics/deliverability` response body. */
export interface Deliverability {
  range: AnalyticsRange
  timezone: string
  total: number
  acceptance: AcceptanceStats
  delivery: DeliveryStats
  sources: Array<DimensionGroup>
  connections: Array<DimensionGroup>
}

export interface EngagementCounts {
  total: number
  automated: number
  human: number
  unique: number
}

export interface EngagementRate {
  engaged_logs: number
  denominator: number
  rate: number
}

/** `GET analytics/engagement` response body (MailAnalyticsService::engagement). */
export interface Engagement {
  range: AnalyticsRange
  timezone: string
  total: number
  interpretation: string
  opens: EngagementCounts
  clicks: EngagementCounts
  open_rate: EngagementRate
  click_rate: EngagementRate
  engagement_interpretation: string
}

export type AnomalyObservation =
  | { type: 'volume_change'; current: number; prior: number; percentage_change: number }
  | {
      type: 'failure_rate_change'
      current_failure_rate: number
      prior_failure_rate: number
      percentage_point_change: number
    }
  | { type: 'newly_active_source'; source: string; total: number }
  | { type: 'inactive_source'; source: string; total: number }
  | {
      type: 'hourly_distribution_shift'
      hour: number
      current: number
      prior: number
      current_percentage: number
      prior_percentage: number
      percentage_point_change: number
    }
  | {
      type: 'weekday_distribution_shift'
      weekday: number
      current: number
      prior: number
      current_percentage: number
      prior_percentage: number
      percentage_point_change: number
    }
  | {
      type: 'connection_failure_rate_change'
      connection: string
      current_failure_rate: number
      prior_failure_rate: number
      percentage_point_change: number
    }

export interface ComparisonCoverage {
  complete: boolean
  retained_from: string | null
  continuity_from: string | null
  configured_retained_from: string | null
}

/** `GET analytics/anomalies` response body. */
export interface Anomalies {
  range: AnalyticsRange
  timezone: string
  total: number
  current: { total: number; accepted: number; failed: number }
  prior: { total: number; accepted: number; failed: number }
  prior_range: AnalyticsRange
  timestamp_coverage: TimestampCoverage
  comparison_coverage: ComparisonCoverage
  observations: Array<AnomalyObservation>
}
