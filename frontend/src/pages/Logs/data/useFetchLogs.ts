import request from '@common/helpers/request'
import { useQuery } from '@tanstack/react-query'

export interface LogQueryType {
  searchKeyValue?: string
  pageNo: number
  limit: number
  to_addr?: string
  status?: string
  delivery_status?: string
  connection_id?: string
  source_plugin?: string
  date_from?: string
  date_to?: string
}

export type LogAttempt = {
  connection: string
  status: 'sent' | 'accepted' | 'failed'
  error?: string | null
}

export type LogDetail = {
  message: string
  headers: Record<string, string>
  attachments: Array<string>
  attempts?: Array<LogAttempt>
}

export type DeliveryStatusValue = 'delivered' | 'bounced' | 'spam' | 'blocked' | 'deferred' | 'accepted'

export type DeliveryEvent = {
  recipient: string
  status: string
  terminal: number
  occurred_at: string | null
}

export type ResendSummary = {
  id: number
  status: string
  created_at: string
}

/** One folded open/click row for a single message (LogService::engagementFor), for the detail view. */
export type EngagementEvent = {
  type: 'open' | 'click' | string
  target: string
  hits: number
  automated_hits: number
  first_at: string | null
  last_at: string | null
}

export type LogType = {
  id: number
  status: string
  subject: string
  to_addr: Array<string>
  cc?: Array<string> | null
  bcc?: Array<string> | null
  details: LogDetail
  debug_info: Array<string>
  retry_count: number
  connection: string | null
  sender?: string | null
  message_id?: string | null
  tracking_id?: string | null
  // Classifier verdict for a failed send (transient/rate_limited/auth/invalid_recipient/permanent).
  failure_class?: string | null
  // Real provider delivery status, only trustworthy when `delivery_verified` is true.
  delivery_status?: DeliveryStatusValue | string | null
  delivery_updated_at?: string | null
  delivery_verified?: boolean
  delivery_events?: Array<DeliveryEvent>
  // Per-message opens/clicks (with click targets), for the detail view.
  engagement?: Array<EngagementEvent>
  // Detail-only resend history: the parent id this was resent from, and the resends launched from it.
  resend_of?: number | null
  resends?: Array<ResendSummary>
  created_at: string
  updated_at: string
}

type FetchLogsType = {
  logs: Array<LogType>
  count: number
  current: number
  pages: number
}

export default function useFetchLogs(searchData: LogQueryType) {
  const queryId = `logs-${searchData.pageNo}`
  const { data, isLoading, isFetching, refetch } = useQuery({
    refetchOnWindowFocus: false,
    queryKey: ['all_logs', queryId, searchData],
    queryFn: async ({ signal }) =>
      request<FetchLogsType>({ action: 'logs/all', data: searchData, signal })
  })
  return {
    isLoading,
    refetch,
    isLogsFetching: isFetching,
    logs: data?.data?.logs ?? [],
    total: data?.data?.count ?? 0,
    current: data?.data?.current ?? 0,
    pages: data?.data?.pages ?? 0
  }
}
