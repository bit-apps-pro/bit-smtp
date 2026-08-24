import { type ReactNode } from 'react'
import request from '@common/helpers/request'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import { type Mock, describe, expect, it, vi } from 'vitest'
import useRetryQueue, { type RetryQueueItem } from './useRetryQueue'

vi.mock('@common/helpers/request', () => ({ default: vi.fn() }))

function wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>
}

const items: RetryQueueItem[] = [
  {
    id: 1,
    attempts: 2,
    max_attempts: 5,
    failure_class: 'transient',
    connection_chain: 'Primary SMTP, Backup SES',
    next_attempt_at: '2026-08-24T10:00:00Z',
    created_at: '2026-08-24T09:00:00Z',
    locked: false
  }
]

describe('useRetryQueue', () => {
  it('fetches the retry queue over GET and surfaces depth and items', async () => {
    ;(request as Mock).mockResolvedValue({
      status: 'success',
      code: 'SUCCESS',
      message: undefined,
      data: { enabled: true, depth: 1, items }
    })

    const { result } = renderHook(() => useRetryQueue(), { wrapper })
    await waitFor(() => expect(result.current.isLoading).toBe(false))

    expect(request).toHaveBeenCalledWith(
      expect.objectContaining({ action: 'mail/retry-queue', method: 'GET' })
    )
    expect(result.current.enabled).toBe(true)
    expect(result.current.depth).toBe(1)
    expect(result.current.items).toEqual(items)
  })

  it('exposes safe defaults (depth 0, empty items) before data resolves', () => {
    ;(request as Mock).mockReturnValue(new Promise(() => {}))

    const { result } = renderHook(() => useRetryQueue(), { wrapper })

    expect(result.current.enabled).toBe(false)
    expect(result.current.depth).toBe(0)
    expect(result.current.items).toEqual([])
  })
})
