import request from '@common/helpers/request'
import { type Connection } from '@pages/Connections/types'
import { useMutation } from '@tanstack/react-query'

export interface ConnectionDeliveryStatus {
  state: string
  detail: string
}

export interface ConnectionTestResult {
  ok: boolean
  to: string
  debug: unknown
  delivery?: ConnectionDeliveryStatus | null
  error?: string
  /** Backend `FailureCategory` of a failed send, when the provider was reached. */
  failureClass?: string
  /** Per-field validation messages keyed by provider field key. */
  fieldErrors?: Record<string, string>
}

interface ConnectionTestResponseData {
  debug?: unknown
  delivery?: ConnectionDeliveryStatus | null
  failure_class?: string
  errors?: Record<string, string>
}

export interface ConnectionTestPayload {
  connection: Connection
  to: string
}

/** Normalises the test endpoint's envelope (legacy bare debug arrays included) into a ConnectionTestResult. */
function toTestResult(
  to: string,
  status: 'success' | 'error',
  message: string | undefined,
  raw: ConnectionTestResponseData | unknown[] | undefined
): ConnectionTestResult {
  const data = Array.isArray(raw) ? { debug: raw } : raw ?? {}

  return {
    ok: status === 'success',
    to,
    debug: data.debug ?? [],
    delivery: data.delivery ?? null,
    error: status === 'error' ? message : undefined,
    failureClass: data.failure_class,
    fieldErrors: data.errors
  }
}

/** Sends a test email through an unsaved connection and reports the outcome. */
export default function useTestConnection() {
  return useMutation({
    mutationFn: async ({ connection, to }: ConnectionTestPayload): Promise<ConnectionTestResult> => {
      const response = await request<ConnectionTestResponseData>({
        action: 'mail/connections/test',
        data: { ...connection, to }
      })

      return toTestResult(to, response.status, response.message, response.data)
    }
  })
}
