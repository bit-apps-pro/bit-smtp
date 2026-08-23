import { renderWithProviders } from '@config/test-utils'
import usePreferences, {
  useExportPreferences,
  useImportPreferences,
  useSavePreferences
} from '@pages/Settings/data/usePreferences'
import { type Preferences } from '@pages/Settings/types'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { type Mock, beforeEach, describe, expect, it, vi } from 'vitest'
import SettingsPage from './SettingsPage'

vi.mock('@pages/Settings/data/usePreferences', () => ({
  default: vi.fn(),
  useSavePreferences: vi.fn(),
  useExportPreferences: vi.fn(),
  useImportPreferences: vi.fn()
}))

const preferences: Preferences = {
  logging_enabled: true,
  log_retention_days: 30,
  log_store_body: 'full',
  send_timeout_seconds: 30,
  retry_enabled: false,
  retry_max_attempts: 3,
  retry_backoff: 'exponential',
  retry_on_classes: ['TransportException'],
  health_check_enabled: false,
  health_check_interval: 'daily',
  notify_cooldown_minutes: 0,
  notify_events: ['connection.failed'],
  uninstall_purge: true,
  tracking_enabled: false
}

describe('SettingsPage', () => {
  const saveMutate = vi.fn()

  beforeEach(() => {
    vi.clearAllMocks()
    ;(usePreferences as Mock).mockReturnValue({ data: preferences, isPending: false })
    ;(useSavePreferences as Mock).mockReturnValue({ mutate: saveMutate, isPending: false })
    ;(useExportPreferences as Mock).mockReturnValue({ mutate: vi.fn(), isPending: false })
    ;(useImportPreferences as Mock).mockReturnValue({ mutate: vi.fn(), isPending: false })
  })

  it('shows a loading spinner while preferences are pending', () => {
    ;(usePreferences as Mock).mockReturnValue({ data: undefined, isPending: true })

    const { container } = renderWithProviders(<SettingsPage />)

    expect(container.querySelector('.ant-spin')).toBeInTheDocument()
  })

  it('renders the four preference group cards', () => {
    renderWithProviders(<SettingsPage />)

    expect(screen.getByText('General & Logging')).toBeInTheDocument()
    expect(screen.getByText('Reliability')).toBeInTheDocument()
    expect(screen.getByText('Health & Notifications')).toBeInTheDocument()
    expect(screen.getByText('Privacy & Data')).toBeInTheDocument()
  })

  it('saves the edited value, carrying the untouched array fields through unchanged', async () => {
    renderWithProviders(<SettingsPage />)

    await userEvent.clear(screen.getByLabelText('Log retention (days)'))
    await userEvent.type(screen.getByLabelText('Log retention (days)'), '60')
    await userEvent.click(screen.getByRole('button', { name: /^save$/i }))

    expect(saveMutate).toHaveBeenCalledWith(
      expect.objectContaining({
        log_retention_days: 60,
        retry_on_classes: ['TransportException'],
        notify_events: ['connection.failed']
      }),
      expect.objectContaining({ onSuccess: expect.any(Function), onError: expect.any(Function) })
    )
  })

  it('blocks save and shows inline validation when log_retention_days is out of range', async () => {
    renderWithProviders(<SettingsPage />)

    await userEvent.clear(screen.getByLabelText('Log retention (days)'))
    await userEvent.type(screen.getByLabelText('Log retention (days)'), '500')
    await userEvent.click(screen.getByRole('button', { name: /^save$/i }))

    expect(await screen.findByText('Enter a number of days between 1 and 200')).toBeInTheDocument()
    expect(saveMutate).not.toHaveBeenCalled()
  })

  it('blocks save when log_retention_days is cleared to zero', async () => {
    renderWithProviders(<SettingsPage />)

    await userEvent.clear(screen.getByLabelText('Log retention (days)'))
    await userEvent.type(screen.getByLabelText('Log retention (days)'), '0')
    await userEvent.click(screen.getByRole('button', { name: /^save$/i }))

    expect(await screen.findByText('Enter a number of days between 1 and 200')).toBeInTheDocument()
    expect(saveMutate).not.toHaveBeenCalled()
  })
})
