import { type ReactNode } from 'react'
import request from '@common/helpers/request'
import { type AnalyticsRangeParams } from '@pages/Analytics/types'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import { type Mock, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  AnalyticsApiError,
  LOGGING_DISABLED_CODE,
  analyticsQueryState,
  useOverview
} from './useAnalytics'

vi.mock('@common/helpers/request', () => ({ default: vi.fn() }))

beforeEach(() => {
  vi.clearAllMocks()
})

function wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>
}

const params: AnalyticsRangeParams = { start: '2026-01-01T00:00:00Z', end: '2026-01-31T00:00:00Z' }

describe('useOverview', () => {
  it('GETs analytics/overview with the range as query params, omitting an unset bucket', async () => {
    ;(request as Mock).mockResolvedValue({
      status: 'success',
      code: 'SUCCESS',
      message: undefined,
      data: { total: 5 }
    })

    const { result } = renderHook(() => useOverview(params), { wrapper })
    await waitFor(() => expect(result.current.isPending).toBe(false))

    expect(request).toHaveBeenCalledWith({
      action: 'analytics/overview',
      method: 'GET',
      queryParam: { start: params.start, end: params.end }
    })
    expect(result.current.data).toEqual({ loggingDisabled: false, data: { total: 5 } })
  })

  it('resolves logging_disabled as data, not a query error, when the code matches (not the status)', async () => {
    ;(request as Mock).mockResolvedValue({
      status: 'error',
      code: LOGGING_DISABLED_CODE,
      message: 'Email analytics require Bit SMTP logging to be enabled.',
      data: []
    })

    const { result } = renderHook(() => useOverview(params), { wrapper })
    await waitFor(() => expect(result.current.isPending).toBe(false))

    expect(result.current.isError).toBe(false)
    expect(result.current.data).toEqual({ loggingDisabled: true })
  })

  it('throws AnalyticsApiError, surfaced as a query error, for a genuine failure code', async () => {
    ;(request as Mock).mockResolvedValue({
      status: 'error',
      code: 'bit_smtp_invalid_analytics_range',
      message: 'The start timestamp must be before the end timestamp.',
      data: []
    })

    const { result } = renderHook(() => useOverview(params), { wrapper })
    await waitFor(() => expect(result.current.isError).toBe(true))

    expect(result.current.error).toBeInstanceOf(AnalyticsApiError)
    expect(result.current.error?.code).toBe('bit_smtp_invalid_analytics_range')
  })

  it('bucket/range changes produce a different query key (react-query treats it as a new query)', async () => {
    ;(request as Mock).mockResolvedValue({
      status: 'success',
      code: 'SUCCESS',
      message: undefined,
      data: {}
    })

    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    const { result, rerender } = renderHook(({ p }: { p: AnalyticsRangeParams }) => useOverview(p), {
      wrapper: ({ children }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>,
      initialProps: { p: params }
    })
    await waitFor(() => expect(result.current.isPending).toBe(false))
    expect(client.getQueryCache().findAll({ queryKey: ['analytics', 'overview'] })).toHaveLength(1)

    rerender({ p: { ...params, bucket: 'day' } })
    await waitFor(() => expect(request).toHaveBeenCalledTimes(2))

    expect(client.getQueryCache().findAll({ queryKey: ['analytics', 'overview'] })).toHaveLength(2)
  })
})

describe('useDeliverability', () => {
  it('is no longer exported - Top sources/connections read overview.top_sources/top_connections instead', async () => {
    const module: Record<string, unknown> = await import('./useAnalytics')
    expect('useDeliverability' in module).toBe(false)
  })
})

describe('analyticsQueryState', () => {
  it('maps loading/error/logging-disabled/ready in that precedence', () => {
    expect(analyticsQueryState({ isPending: true, isError: false } as never)).toEqual({
      status: 'loading'
    })
    expect(
      analyticsQueryState({
        isPending: false,
        isError: true,
        error: new AnalyticsApiError('x', 'boom')
      } as never)
    ).toEqual({ status: 'error', message: 'boom' })
    expect(
      analyticsQueryState({ isPending: false, isError: false, data: { loggingDisabled: true } } as never)
    ).toEqual({ status: 'logging-disabled' })
    expect(
      analyticsQueryState({
        isPending: false,
        isError: false,
        data: { loggingDisabled: false, data: 42 }
      } as never)
    ).toEqual({ status: 'ready', data: 42 })
  })
})
