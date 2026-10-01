import request from '@common/helpers/request'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

export const TELEMETRY_QUERY_KEY = ['telemetry-status']

/** Whether the site owner answered the sharing question, and whether sharing is on. */
export interface TelemetryStatus {
  answered: boolean
  allowed: boolean
}

/** Unwrap a telemetry response envelope, throwing on an error or malformed body so callers fail closed. */
function toStatus(response: Awaited<ReturnType<typeof request<TelemetryStatus>>>): TelemetryStatus {
  const { answered, allowed } = response.data ?? {}
  if (response.status !== 'success' || typeof answered !== 'boolean') {
    throw new Error(response.message || 'Telemetry request failed')
  }
  return { answered, allowed: allowed === true }
}

/** Load the stored sharing answer once per page load. */
export function useTelemetryStatus() {
  return useQuery({
    queryKey: TELEMETRY_QUERY_KEY,
    staleTime: Infinity,
    refetchOnWindowFocus: false,
    retry: false,
    queryFn: async () =>
      toStatus(await request<TelemetryStatus>({ action: 'telemetry/status', method: 'GET' }))
  })
}

/** Save a yes/no sharing answer and cache the server's resulting status. */
export function useSaveTelemetryConsent() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (allow: boolean) =>
      toStatus(await request<TelemetryStatus>({ action: 'telemetry/consent', data: { allow } })),
    onSuccess: status => queryClient.setQueryData(TELEMETRY_QUERY_KEY, status)
  })
}
