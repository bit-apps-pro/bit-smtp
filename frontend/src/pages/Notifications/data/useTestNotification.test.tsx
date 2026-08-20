import { type ReactNode } from 'react'
import request from '@common/helpers/request'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import { type Mock, describe, expect, it, vi } from 'vitest'
import useTestNotification from './useTestNotification'

vi.mock('@common/helpers/request', () => ({ default: vi.fn() }))

function wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({ defaultOptions: { mutations: { retry: false } } })
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>
}

describe('useTestNotification', () => {
  it('posts only the selected channel without putting credentials in the URL', async () => {
    ;(request as Mock).mockResolvedValue({
      status: 'success',
      code: 'SUCCESS',
      message: 'Test notification sent.',
      data: []
    })
    const { result } = renderHook(() => useTestNotification(), { wrapper })

    result.current.mutate({ channel: 'slack' })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(request).toHaveBeenCalledWith({
      action: 'mail/notifications/test',
      data: { channel: 'slack' }
    })
    expect(result.current.data).toEqual({ ok: true, error: undefined })
  })

  it('returns a safe error result when the notification test is rejected', async () => {
    ;(request as Mock).mockResolvedValue({
      status: 'error',
      code: 'ERROR',
      message: 'Notification test failed.',
      data: []
    })
    const { result } = renderHook(() => useTestNotification(), { wrapper })

    result.current.mutate({ channel: 'telegram' })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(result.current.data).toEqual({ ok: false, error: 'Notification test failed.' })
  })
})
