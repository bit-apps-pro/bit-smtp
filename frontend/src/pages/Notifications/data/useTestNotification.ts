import request from '@common/helpers/request'
import { useMutation } from '@tanstack/react-query'

export type NotificationChannel = 'slack' | 'telegram'

export interface TestNotificationPayload {
  channel: NotificationChannel
}

export interface TestNotificationResult {
  ok: boolean
  error?: string
}

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
