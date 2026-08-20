import request from '@common/helpers/request'
import { type Connection } from '@pages/Connections/types'
import { useMutation } from '@tanstack/react-query'

export interface ConnectionDeliveryStatus {
  state: string
  detail: string
}

export interface ConnectionTestResult {
  ok: boolean
  debug: unknown
  delivery?: ConnectionDeliveryStatus | null
  error?: string
}

interface ConnectionTestResponseData {
  debug?: unknown
  delivery?: ConnectionDeliveryStatus | null
}

export interface ConnectionTestPayload {
  connection: Connection
  to: string
}

export default function useTestConnection() {
  return useMutation({
    mutationFn: async ({ connection, to }: ConnectionTestPayload): Promise<ConnectionTestResult> => {
      const response = await request<ConnectionTestResponseData>({
        action: 'mail/connections/test',
        data: { ...connection, to }
      })

      const raw = response.data
      const debug = Array.isArray(raw) ? raw : raw?.debug ?? []
      const delivery = Array.isArray(raw) ? null : raw?.delivery ?? null

      return {
        ok: response.status === 'success',
        debug,
        delivery,
        error: response.status === 'error' ? response.message : undefined
      }
    }
  })
}
