import request from '@common/helpers/request'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { RETRY_QUEUE_QUERY_KEY } from './useRetryQueue'

export interface FlushRetryQueueResult {
  ok: boolean
  deleted: number
  message?: string
}

/** Discard every queued retry, then refresh the cached queue on success. */
export default function useFlushRetryQueue() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (): Promise<FlushRetryQueueResult> => {
      const response = await request<{ deleted: number }>({ action: 'mail/retry-queue/flush' })
      return {
        ok: response.status === 'success',
        deleted: response.data?.deleted ?? 0,
        message: response.message
      }
    },
    onSuccess: result => {
      if (result.ok) {
        return queryClient.invalidateQueries({ queryKey: RETRY_QUEUE_QUERY_KEY })
      }
      return undefined
    }
  })
}
