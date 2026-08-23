import { type ReactNode } from 'react'
import request from '@common/helpers/request'
import { type Preferences } from '@pages/Settings/types'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import { type Mock, describe, expect, it, vi } from 'vitest'
import usePreferences, {
  useExportPreferences,
  useImportPreferences,
  useSavePreferences
} from './usePreferences'

vi.mock('@common/helpers/request', () => ({ default: vi.fn() }))

function wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } }
  })
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>
}

const preferences: Preferences = {
  logging_enabled: true,
  log_retention_days: 30,
  log_store_body: 'full',
  send_timeout_seconds: 30,
  retry_enabled: false,
  retry_max_attempts: 3,
  retry_backoff: 'exponential',
  retry_on_classes: [],
  health_check_enabled: false,
  health_check_interval: 'daily',
  notify_cooldown_minutes: 0,
  notify_events: [],
  uninstall_purge: true,
  tracking_enabled: false
}

describe('usePreferences', () => {
  it('GETs preferences and unwraps the preferences blob', async () => {
    ;(request as Mock).mockResolvedValue({
      status: 'success',
      code: 'SUCCESS',
      message: undefined,
      data: { preferences }
    })

    const { result } = renderHook(() => usePreferences(), { wrapper })
    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(request).toHaveBeenCalledWith({ action: 'preferences', method: 'GET' })
    expect(result.current.data).toEqual(preferences)
  })
})

describe('useSavePreferences', () => {
  it('POSTs the given values to preferences/save', async () => {
    ;(request as Mock).mockResolvedValue({
      status: 'success',
      code: 'SUCCESS',
      message: undefined,
      data: { preferences: { ...preferences, log_retention_days: 60 } }
    })

    const { result } = renderHook(() => useSavePreferences(), { wrapper })
    result.current.mutate({ log_retention_days: 60 })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(request).toHaveBeenCalledWith({
      action: 'preferences/save',
      data: { log_retention_days: 60 }
    })
  })
})

describe('useExportPreferences', () => {
  it('GETs preferences/export and unwraps the preferences blob', async () => {
    ;(request as Mock).mockResolvedValue({
      status: 'success',
      code: 'SUCCESS',
      message: undefined,
      data: { preferences }
    })

    const { result } = renderHook(() => useExportPreferences(), { wrapper })
    result.current.mutate()

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(request).toHaveBeenCalledWith({ action: 'preferences/export', method: 'GET' })
    expect(result.current.data).toEqual(preferences)
  })
})

describe('useImportPreferences', () => {
  it('POSTs the given payload to preferences/import', async () => {
    ;(request as Mock).mockResolvedValue({
      status: 'success',
      code: 'SUCCESS',
      message: undefined,
      data: { preferences }
    })

    const { result } = renderHook(() => useImportPreferences(), { wrapper })
    result.current.mutate({ preferences })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(request).toHaveBeenCalledWith({ action: 'preferences/import', data: { preferences } })
  })
})
