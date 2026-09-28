import { type ReactNode } from 'react'
import request from '@common/helpers/request'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { type Mock, beforeEach, describe, expect, it, vi } from 'vitest'
import LogRetentionSettings from './LogRetentionSettings'

vi.mock('@common/helpers/request', () => ({ default: vi.fn() }))

/** Render with a fresh query client so each test starts from an empty preferences cache. */
function renderPopover(): QueryClient {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } }
  })
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  )
  render(<LogRetentionSettings />, { wrapper })
  return client
}

/** Route mocked requests: GET returns the saved blob, save echoes the posted days. */
function mockPreferences(savedDays: number) {
  ;(request as Mock).mockImplementation(({ action, data }) =>
    Promise.resolve(
      action === 'preferences'
        ? { status: 'success', data: { preferences: { log_retention_days: savedDays } } }
        : { status: 'success', data: { preferences: { log_retention_days: data.log_retention_days } } }
    )
  )
}

describe('LogRetentionSettings', () => {
  beforeEach(() => {
    ;(request as Mock).mockReset()
  })

  it('shows the saved retention days instead of a hardcoded default', async () => {
    mockPreferences(90)
    renderPopover()
    fireEvent.click(screen.getByRole('button', { name: 'Log retention settings' }))

    await waitFor(() => expect(screen.getByRole('spinbutton')).toHaveValue('90'))
  })

  it('saves through preferences and refreshes the shared cache the Settings page reads', async () => {
    mockPreferences(90)
    const client = renderPopover()
    fireEvent.click(screen.getByRole('button', { name: 'Log retention settings' }))
    await waitFor(() => expect(screen.getByRole('spinbutton')).toHaveValue('90'))

    fireEvent.change(screen.getByRole('spinbutton'), { target: { value: '45' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() =>
      expect(request).toHaveBeenCalledWith({
        action: 'preferences/save',
        data: { log_retention_days: 45 }
      })
    )
    await waitFor(() => expect(client.getQueryData(['preferences'])).toEqual({ log_retention_days: 45 }))
  })
})
