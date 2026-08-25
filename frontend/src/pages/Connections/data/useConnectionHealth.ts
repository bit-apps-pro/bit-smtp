import request from '@common/helpers/request'
import { type ConnectionHealthMap } from '@pages/Connections/types'
import { useQuery } from '@tanstack/react-query'

export const CONNECTION_HEALTH_QUERY_KEY = ['connection_health']

/** Load per-connection health on its own cache key, so a probe never invalidates the mail settings. */
export default function useConnectionHealth() {
  const { data, isFetching } = useQuery({
    queryKey: CONNECTION_HEALTH_QUERY_KEY,
    refetchOnWindowFocus: false,
    queryFn: async ({ signal }) =>
      request<{ health: ConnectionHealthMap }>({
        action: 'mail/connections/health',
        method: 'GET',
        signal
      })
  })

  return {
    health: data?.data?.health ?? {},
    isFetching
  }
}
