import { type ReactNode } from 'react'
import request from '@common/helpers/request'
import { MASK_SENTINEL } from '@pages/Connections/types'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import { type Mock, describe, expect, it, vi } from 'vitest'
import useTestNotification, { type TestNotificationPayload } from './useTestNotification'

vi.mock('@common/helpers/request', () => ({ default: vi.fn() }))

/** Wrap the hook in a fresh, retry-free QueryClient. */
function wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({ defaultOptions: { mutations: { retry: false } } })
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>
}

/** Resolve the mocked request with a backend envelope of the given status and message. */
function mockResponse(status: 'success' | 'error', message: string) {
  ;(request as Mock).mockResolvedValue({
    status,
    code: status === 'success' ? 'SUCCESS' : 'ERROR',
    message,
    data: []
  })
}

const slackPayload: TestNotificationPayload = {
  channel: {
    id: 'ch_0123456789ab',
    type: 'slack',
    settings: { webhook_url: 'https://hooks.slack.com/services/T000/B000/secret' }
  }
}

describe('useTestNotification', () => {
  it.each<TestNotificationPayload>([
    slackPayload,
    { channel: { id: 'ch_00000000000e', type: 'email', settings: { recipients: ['ops@example.org'] } } },
    {
      channel: {
        id: 'ch_00000000000f',
        type: 'webhook',
        settings: { url: MASK_SENTINEL, signing_secret: MASK_SENTINEL }
      }
    }
  ])('posts the $channel.type channel instance in the request body', async payload => {
    mockResponse('success', 'Test notification sent.')
    const { result } = renderHook(() => useTestNotification(), { wrapper })

    result.current.mutate(payload)

    await waitFor(() => expect(result.current.isSuccess).toBe(true))
    expect(request).toHaveBeenCalledWith({ action: 'mail/notifications/test', data: payload })
    expect(result.current.data).toEqual({ ok: true, error: undefined })
  })

  it("returns the backend's error message when the notification test is rejected", async () => {
    mockResponse('error', 'Slack rejected the webhook.')
    const { result } = renderHook(() => useTestNotification(), { wrapper })

    result.current.mutate(slackPayload)

    await waitFor(() => expect(result.current.isSuccess).toBe(true))
    expect(result.current.data).toEqual({ ok: false, error: 'Slack rejected the webhook.' })
  })
})
