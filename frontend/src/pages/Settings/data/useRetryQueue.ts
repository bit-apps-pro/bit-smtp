import request from '@common/helpers/request'
import { useQuery } from '@tanstack/react-query'

export const RETRY_QUEUE_QUERY_KEY = ['retry_queue']

export interface RetryQueueItem {
  id: number
  attempts: number
  max_attempts: number
  failure_class: string | null
  connection_chain: string
  next_attempt_at: string
  created_at: string
  locked: boolean
}

interface RetryQueueResponse {
  enabled: boolean
  depth: number
  items: RetryQueueItem[]
}

/** Load the pending retry queue: deferred sends awaiting another delivery attempt. */
export default function useRetryQueue() {
  const { data, isLoading, isFetching, refetch } = useQuery({
    queryKey: RETRY_QUEUE_QUERY_KEY,
    refetchOnWindowFocus: false,
    queryFn: async ({ signal }) =>
      request<RetryQueueResponse>({ action: 'mail/retry-queue', method: 'GET', signal })
  })

  return {
    enabled: data?.data?.enabled ?? false,
    depth: data?.data?.depth ?? 0,
    items: data?.data?.items ?? [],
    isLoading,
    isFetching,
    refetch
  }
}
