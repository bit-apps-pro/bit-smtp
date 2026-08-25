import request from '@common/helpers/request'
import { type ConnectionHealthMap } from '@pages/Connections/types'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { CONNECTION_HEALTH_QUERY_KEY } from './useConnectionHealth'

/** Run an on-demand health probe of every connection, then refresh the cached health map. */
export default function useCheckConnectionHealth() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async () =>
      request<{ health: ConnectionHealthMap }>({ action: 'mail/connections/health/check' }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: CONNECTION_HEALTH_QUERY_KEY })
  })
}
