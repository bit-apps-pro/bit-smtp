import { type ReactNode } from 'react'
import request from '@common/helpers/request'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import { type Mock, describe, expect, it, vi } from 'vitest'
import useMailSources from './useMailSources'

vi.mock('@common/helpers/request', () => ({ default: vi.fn() }))

function wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>
}

describe('useMailSources', () => {
  it('GETs mail/routing/sources and splits seen senders from other installed plugins', async () => {
    ;(request as Mock).mockResolvedValue({
      status: 'success',
      code: 'SUCCESS',
      message: undefined,
      data: {
        sources: [{ value: 'woocommerce', label: 'WooCommerce' }],
        installed: [{ value: 'bit-crm', label: 'Bit CRM' }]
      }
    })

    const { result } = renderHook(() => useMailSources(), { wrapper })

    await waitFor(() => expect(result.current.isPending).toBe(false))

    expect(request).toHaveBeenCalledWith({ action: 'mail/routing/sources', method: 'GET' })
    expect(result.current.data).toEqual({
      seen: [{ value: 'woocommerce', label: 'WooCommerce' }],
      installed: [{ value: 'bit-crm', label: 'Bit CRM' }]
    })
  })
})
