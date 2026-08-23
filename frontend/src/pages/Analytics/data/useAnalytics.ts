import { __ } from '@common/helpers/i18nwrap'
import request from '@common/helpers/request'
import { type AnalyticsRangeParams, type Anomalies, type Overview } from '@pages/Analytics/types'
import { type UseQueryResult, keepPreviousData, useQuery } from '@tanstack/react-query'

/**
 * Momus N1 contract: the response envelope is HTTP 200 even when logging is off - the body carries
 * `status:'error'` + this top-level `code`. The UI branches on `code`, never `status`, so this
 * expected, non-failure state renders an empty state instead of an error toast.
 */
export const LOGGING_DISABLED_CODE = 'bit_smtp_logging_disabled'

export class AnalyticsApiError extends Error {
  code: string

  constructor(code: string, message: string) {
    super(message)
    this.code = code
  }
}

export type AnalyticsResult<T> = { loggingDisabled: true } | { loggingDisabled: false; data: T }

/** Query-string params for the request() helper, with unset filters omitted (never `undefined.toString()`). */
function toQueryParam(params: AnalyticsRangeParams): Record<string, string> {
  const entries: Array<[string, string | undefined]> = [
    ['start', params.start],
    ['end', params.end],
    ['bucket', params.bucket],
    ['plugin', params.plugin],
    ['connection_id', params.connectionId]
  ]
  return Object.fromEntries(entries.filter((entry): entry is [string, string] => entry[1] !== undefined))
}

/** GET one analytics endpoint; resolves the logging-off business state instead of throwing for it. */
async function fetchAnalytics<T>(
  action: string,
  params: AnalyticsRangeParams
): Promise<AnalyticsResult<T>> {
  const response = await request<T>({ action, method: 'GET', queryParam: toQueryParam(params) })
  // `request.ts` types `code` as the generic 'SUCCESS' | 'ERROR' pair, but WP_Error-backed endpoints
  // (like this one) actually echo the WP_Error code verbatim - widen locally rather than the shared type.
  const code = response.code as string
  if (code === LOGGING_DISABLED_CODE) {
    return { loggingDisabled: true }
  }
  if (response.status === 'error') {
    throw new AnalyticsApiError(code, response.message || __('Failed to load analytics.'))
  }
  return { loggingDisabled: false, data: response.data }
}

function useAnalyticsQuery<T>(endpoint: string, params: AnalyticsRangeParams) {
  return useQuery<AnalyticsResult<T>, AnalyticsApiError>({
    queryKey: ['analytics', endpoint, params],
    queryFn: () => fetchAnalytics<T>(`analytics/${endpoint}`, params),
    placeholderData: keepPreviousData,
    refetchOnWindowFocus: false
  })
}

/** Volume/acceptance/delivery summary, time series, and top sources/connections for the filtered range. */
export function useOverview(params: AnalyticsRangeParams) {
  return useAnalyticsQuery<Overview>('overview', params)
}

/** Current-vs-prior-equal-period comparison observations (volume, failure rate, timing shifts). */
export function useAnomalies(params: AnalyticsRangeParams) {
  return useAnalyticsQuery<Anomalies>('anomalies', params)
}

export type AnalyticsQueryState<T> =
  | { status: 'loading' }
  | { status: 'error'; message: string }
  | { status: 'logging-disabled' }
  | { status: 'ready'; data: T }

/** Collapse a query's loading/error/react-query state and the logging-off business state into one union. */
export function analyticsQueryState<T>(
  query: UseQueryResult<AnalyticsResult<T>, AnalyticsApiError>
): AnalyticsQueryState<T> {
  if (query.isPending) return { status: 'loading' }
  if (query.isError) return { status: 'error', message: query.error.message }
  return query.data.loggingDisabled
    ? { status: 'logging-disabled' }
    : { status: 'ready', data: query.data.data }
}
