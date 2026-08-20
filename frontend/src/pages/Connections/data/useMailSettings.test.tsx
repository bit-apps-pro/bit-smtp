import { type ReactNode } from 'react'
import request from '@common/helpers/request'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import { type Mock, describe, expect, it, vi } from 'vitest'
import useMailSettings, { MAIL_SETTINGS_QUERY_KEY } from './useMailSettings'

vi.mock('@common/helpers/request', () => ({ default: vi.fn() }))

function wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>
}

describe('useMailSettings', () => {
  it('GETs mail/settings and returns the settings', async () => {
    ;(request as Mock).mockResolvedValue({
      status: 'success',
      code: 'SUCCESS',
      message: undefined,
      data: { settings: { schema_version: 2, connections: [] } }
    })

    const { result } = renderHook(() => useMailSettings(), { wrapper })

    await waitFor(() => expect(result.current.isPending).toBe(false))

    expect(request).toHaveBeenCalledWith({ action: 'mail/settings', method: 'GET' })
    expect(result.current.data?.schema_version).toBe(2)
  })

  it('uses the mail-settings query key', () => {
    expect(MAIL_SETTINGS_QUERY_KEY).toEqual(['mail-settings'])
  })
})
