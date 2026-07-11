import { type ReactNode } from 'react'
import request from '@common/helpers/request'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import { type Mock, describe, expect, it, vi } from 'vitest'
import useOAuthAuthorize from './useOAuthAuthorize'

vi.mock('@common/helpers/request', () => ({ default: vi.fn() }))

function wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>
}

describe('useOAuthAuthorize', () => {
  it('GETs mail/oauth/authorize with connection_id + provider and returns the consent url', async () => {
    ;(request as Mock).mockResolvedValue({
      status: 'success',
      code: 'SUCCESS',
      message: undefined,
      data: { url: 'https://accounts.google.com/o/oauth2/v2/auth?client_id=abc' }
    })

    const { result } = renderHook(() => useOAuthAuthorize(), { wrapper })

    result.current.mutate({ connectionId: 'conn_1', provider: 'gmail' })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(request).toHaveBeenCalledWith({
      action: 'mail/oauth/authorize',
      method: 'GET',
      queryParam: { connection_id: 'conn_1', provider: 'gmail' }
    })
    expect(result.current.data).toBe('https://accounts.google.com/o/oauth2/v2/auth?client_id=abc')
  })
})
