import { type ReactNode } from 'react'
import request from '@common/helpers/request'
import { type ConnectionHealthMap } from '@pages/Connections/types'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import { type Mock, describe, expect, it, vi } from 'vitest'
import useConnectionHealth from './useConnectionHealth'

vi.mock('@common/helpers/request', () => ({ default: vi.fn() }))

function wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>
}

const health: ConnectionHealthMap = {
  conn_a: {
    status: 'unhealthy',
    circuit: 'open',
    consecutive_failures: 3,
    last_ok_at: '2026-08-24T09:00:00Z',
    last_error: 'SMTP connect() failed',
    last_probe_at: '2026-08-24T10:00:00Z',
    oauth_expires_at: null
  }
}

describe('useConnectionHealth', () => {
  it('GETs mail/connections/health on its own cache key and surfaces the map', async () => {
    ;(request as Mock).mockResolvedValue({
      status: 'success',
      code: 'SUCCESS',
      message: undefined,
      data: { health }
    })

    const { result } = renderHook(() => useConnectionHealth(), { wrapper })
    await waitFor(() => expect(result.current.health).toEqual(health))

    expect(request).toHaveBeenCalledWith(
      expect.objectContaining({ action: 'mail/connections/health', method: 'GET' })
    )
  })

  it('exposes a safe empty map before data resolves', () => {
    ;(request as Mock).mockReturnValue(new Promise(() => {}))

    const { result } = renderHook(() => useConnectionHealth(), { wrapper })

    expect(result.current.health).toEqual({})
  })
})
