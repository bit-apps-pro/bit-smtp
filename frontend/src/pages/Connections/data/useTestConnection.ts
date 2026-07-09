import request from '@common/helpers/request'
import { type Connection } from '@pages/Connections/types'
import { useMutation } from '@tanstack/react-query'

export interface ConnectionTestResult {
  ok: boolean
  debug: string[]
  error?: string
}

export interface ConnectionTestPayload {
  connection: Connection
  to: string
}

export default function useTestConnection() {
  return useMutation({
    mutationFn: async ({ connection, to }: ConnectionTestPayload): Promise<ConnectionTestResult> => {
      const response = await request<string[]>({
        action: 'mail/connections/test',
        data: { ...connection, to }
      })

      return {
        ok: response.status === 'success',
        debug: response.data,
        error: response.status === 'error' ? response.message : undefined
      }
    }
  })
}
