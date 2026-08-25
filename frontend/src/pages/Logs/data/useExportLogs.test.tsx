import { type ReactNode } from 'react'
import request from '@common/helpers/request'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import { type Mock, afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import useExportLogs from './useExportLogs'

vi.mock('@common/helpers/request', () => ({ default: vi.fn() }))

function wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } }
  })
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>
}

const createObjectURL = vi.fn(() => 'blob:mock-url')
const revokeObjectURL = vi.fn()
let clickSpy: ReturnType<typeof vi.spyOn>

beforeEach(() => {
  URL.createObjectURL = createObjectURL
  URL.revokeObjectURL = revokeObjectURL
  clickSpy = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
})

afterEach(() => {
  vi.clearAllMocks()
  clickSpy.mockRestore()
})

describe('useExportLogs', () => {
  it('POSTs the active filters to logs/export and downloads the returned CSV', async () => {
    ;(request as Mock).mockResolvedValue({
      status: 'success',
      code: 'SUCCESS',
      message: undefined,
      data: { csv: 'id,status\n1,failed\n', filename: 'bit-smtp-logs.csv', truncated: false, count: 1 }
    })

    const { result } = renderHook(() => useExportLogs(), { wrapper })
    result.current.mutate({ status: 'failed', connection_id: 'conn-a' })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(request).toHaveBeenCalledWith({
      action: 'logs/export',
      data: { status: 'failed', connection_id: 'conn-a' }
    })
    expect(createObjectURL).toHaveBeenCalledTimes(1)
    expect(clickSpy).toHaveBeenCalledTimes(1)
    expect(revokeObjectURL).toHaveBeenCalledTimes(1)
  })

  it('does not trigger a download when the server returns an error', async () => {
    ;(request as Mock).mockResolvedValue({
      status: 'error',
      code: 'ERROR',
      message: 'boom',
      data: {}
    })

    const { result } = renderHook(() => useExportLogs(), { wrapper })
    result.current.mutate({})

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(createObjectURL).not.toHaveBeenCalled()
    expect(clickSpy).not.toHaveBeenCalled()
  })
})
