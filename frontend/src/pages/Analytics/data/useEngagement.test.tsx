import { type ReactNode } from 'react'
import request from '@common/helpers/request'
import { type AnalyticsRangeParams } from '@pages/Analytics/types'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import { type Mock, beforeEach, describe, expect, it, vi } from 'vitest'
import useEngagement from './useEngagement'

vi.mock('@common/helpers/request', () => ({ default: vi.fn() }))

beforeEach(() => {
  vi.clearAllMocks()
})

function wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>
}

const params: AnalyticsRangeParams = { start: '2026-01-01T00:00:00Z', end: '2026-01-31T00:00:00Z' }

describe('useEngagement', () => {
  it('GETs analytics/engagement with the range as query params, omitting an unset bucket', async () => {
    ;(request as Mock).mockResolvedValue({
      status: 'success',
      code: 'SUCCESS',
      message: undefined,
      data: { opens: { total: 3, automated: 1, human: 2, unique: 2 } }
    })

    const { result } = renderHook(() => useEngagement(params), { wrapper })
    await waitFor(() => expect(result.current.isPending).toBe(false))

    expect(request).toHaveBeenCalledWith({
      action: 'analytics/engagement',
      method: 'GET',
      queryParam: { start: params.start, end: params.end }
    })
    expect(result.current.data).toEqual({
      loggingDisabled: false,
      data: { opens: { total: 3, automated: 1, human: 2, unique: 2 } }
    })
  })

  it('resolves logging_disabled as data, not a query error, when the code matches', async () => {
    ;(request as Mock).mockResolvedValue({
      status: 'error',
      code: 'bit_smtp_logging_disabled',
      message: 'Email analytics require Bit SMTP logging to be enabled.',
      data: []
    })

    const { result } = renderHook(() => useEngagement(params), { wrapper })
    await waitFor(() => expect(result.current.isPending).toBe(false))

    expect(result.current.isError).toBe(false)
    expect(result.current.data).toEqual({ loggingDisabled: true })
  })
})
