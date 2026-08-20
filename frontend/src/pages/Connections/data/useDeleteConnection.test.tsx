import { type ReactNode } from 'react'
import request from '@common/helpers/request'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import { type Mock, describe, expect, it, vi } from 'vitest'
import useDeleteConnection from './useDeleteConnection'
import { MAIL_SETTINGS_QUERY_KEY } from './useMailSettings'

vi.mock('@common/helpers/request', () => ({ default: vi.fn() }))

function createWrapper() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const invalidateQueries = vi.spyOn(client, 'invalidateQueries')
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  )
  return { wrapper, invalidateQueries }
}

describe('useDeleteConnection', () => {
  it('POSTs the id to mail/connections/delete and invalidates mail-settings', async () => {
    ;(request as Mock).mockResolvedValue({
      status: 'success',
      code: 'SUCCESS',
      message: 'Connection deleted',
      data: 'Connection deleted'
    })

    const { wrapper, invalidateQueries } = createWrapper()
    const { result } = renderHook(() => useDeleteConnection(), { wrapper })

    result.current.mutate('conn_123')

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(request).toHaveBeenCalledWith({
      action: 'mail/connections/delete',
      data: { id: 'conn_123' }
    })
    expect(invalidateQueries).toHaveBeenCalledWith({ queryKey: MAIL_SETTINGS_QUERY_KEY })
  })
})
