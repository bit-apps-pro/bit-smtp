import { type ReactNode } from 'react'
import request from '@common/helpers/request'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import { type Mock, describe, expect, it, vi } from 'vitest'
import useCheckConnectionHealth from './useCheckConnectionHealth'

vi.mock('@common/helpers/request', () => ({ default: vi.fn() }))

describe('useCheckConnectionHealth', () => {
  it('POSTs mail/connections/health/check and invalidates the health cache on success', async () => {
    ;(request as Mock).mockResolvedValue({
      status: 'success',
      code: 'SUCCESS',
      message: undefined,
      data: { health: {} }
    })

    const client = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } }
    })
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')
    const wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={client}>{children}</QueryClientProvider>
    )

    const { result } = renderHook(() => useCheckConnectionHealth(), { wrapper })
    result.current.mutate()

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(request).toHaveBeenCalledWith({ action: 'mail/connections/health/check' })
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ['connection_health'] })
  })
})
