import request from '@common/helpers/request'
import { type AlertChannel } from '@pages/Connections/types'
import { useMutation } from '@tanstack/react-query'

export interface TestNotificationPayload {
  channel: Pick<AlertChannel, 'id' | 'type' | 'settings'>
}

export interface TestNotificationResult {
  ok: boolean
  error?: string
}

/** Send a test alert through one channel's current (possibly unsaved) configuration. */
export default function useTestNotification() {
  return useMutation({
    mutationFn: async ({ channel }: TestNotificationPayload): Promise<TestNotificationResult> => {
      const response = await request<unknown>({
        action: 'mail/notifications/test',
        data: { channel }
      })

      return {
        ok: response.status === 'success',
        error: response.status === 'error' ? response.message : undefined
      }
    }
  })
}
