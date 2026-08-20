import { type ReactNode } from 'react'
import request from '@common/helpers/request'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import { type Mock, describe, expect, it, vi } from 'vitest'
import useProviders from './useProviders'

vi.mock('@common/helpers/request', () => ({ default: vi.fn() }))

function wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>
}

describe('useProviders', () => {
  it('GETs mail/providers and returns the provider list', async () => {
    ;(request as Mock).mockResolvedValue({
      status: 'success',
      code: 'SUCCESS',
      message: undefined,
      data: {
        providers: [{ key: 'other_smtp', label: 'Other SMTP', kind: 'smtp', fields: [] }]
      }
    })

    const { result } = renderHook(() => useProviders(), { wrapper })

    await waitFor(() => expect(result.current.isPending).toBe(false))

    expect(request).toHaveBeenCalledWith({ action: 'mail/providers', method: 'GET' })
    expect(result.current.data?.[0]?.key).toBe('other_smtp')
  })
})
