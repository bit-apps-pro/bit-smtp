import notify from '@components/Toaster/Toaster'
import useFlushRetryQueue from '@pages/Settings/data/useFlushRetryQueue'
import useRetryQueue, { type RetryQueueItem } from '@pages/Settings/data/useRetryQueue'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { type Mock, beforeEach, describe, expect, it, vi } from 'vitest'
import RetryQueuePanel from './RetryQueuePanel'

vi.mock('@components/Toaster/Toaster', () => ({ default: { success: vi.fn(), error: vi.fn() } }))
vi.mock('@pages/Settings/data/useRetryQueue', () => ({ default: vi.fn() }))
vi.mock('@pages/Settings/data/useFlushRetryQueue', () => ({ default: vi.fn() }))

const items: RetryQueueItem[] = [
  {
    id: 1,
    attempts: 2,
    max_attempts: 5,
    failure_class: 'rate_limited',
    connection_chain: 'Primary SMTP, Backup SES',
    next_attempt_at: '2026-08-24T10:00:00Z',
    created_at: '2026-08-24T09:00:00Z',
    locked: false
  }
]

const refetch = vi.fn()

/** Point the mocked useRetryQueue at a queue snapshot, defaulting to a single-item queue. */
function mockQueue(overrides: Partial<ReturnType<typeof useRetryQueue>> = {}) {
  ;(useRetryQueue as Mock).mockReturnValue({
    enabled: true,
    depth: items.length,
    items,
    isLoading: false,
    isFetching: false,
    refetch,
    ...overrides
  })
}

describe('RetryQueuePanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    ;(useFlushRetryQueue as Mock).mockReturnValue({ mutate: vi.fn(), isPending: false })
    mockQueue()
  })

  it('renders the queue depth and a row per queued retry with its failure label', () => {
    render(<RetryQueuePanel />)

    expect(screen.getByText('Queued retries')).toBeInTheDocument()
    expect(screen.getByText('1')).toBeInTheDocument()
    expect(screen.getByText('Rate limited')).toBeInTheDocument()
    expect(screen.getByText('2/5')).toBeInTheDocument()
    expect(screen.getByText('Primary SMTP')).toBeInTheDocument()
    expect(screen.getByText('Backup SES')).toBeInTheDocument()
  })

  it('shows an empty state and no clear action when the queue is empty', () => {
    mockQueue({ depth: 0, items: [] })
    render(<RetryQueuePanel />)

    expect(screen.getByText('No retries queued')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Clear queue' })).not.toBeInTheDocument()
  })

  it('warns that queued sends are stuck when automatic retry is turned off', () => {
    mockQueue({ enabled: false })
    render(<RetryQueuePanel />)

    expect(screen.getByText('Automatic retry is turned off')).toBeInTheDocument()
  })

  it('does not warn while automatic retry is enabled', () => {
    render(<RetryQueuePanel />)

    expect(screen.queryByText('Automatic retry is turned off')).not.toBeInTheDocument()
  })

  it('notes when the table shows only the first slice of a larger queue', () => {
    mockQueue({ depth: 150 })
    render(<RetryQueuePanel />)

    expect(screen.getByText('Showing the first 1 of 150 queued retries.')).toBeInTheDocument()
  })

  it('refetches the queue when Refresh is clicked', async () => {
    const localRefetch = vi.fn()
    mockQueue({ refetch: localRefetch })
    render(<RetryQueuePanel />)

    await userEvent.click(screen.getByRole('button', { name: 'Refresh' }))

    expect(localRefetch).toHaveBeenCalled()
  })

  it('flushes the queue only after the clear action is confirmed', async () => {
    const mutate = vi.fn()
    ;(useFlushRetryQueue as Mock).mockReturnValue({ mutate, isPending: false })
    render(<RetryQueuePanel />)

    await userEvent.click(screen.getByRole('button', { name: 'Clear queue' }))
    expect(mutate).not.toHaveBeenCalled()

    await userEvent.click(await screen.findByRole('button', { name: 'Discard' }))

    expect(mutate).toHaveBeenCalledTimes(1)
  })

  it('shows a success toast with the server message after a confirmed flush', async () => {
    const mutate = vi.fn((_vars, options) =>
      options.onSuccess({ ok: true, deleted: 1, message: 'Cleared 1 queued retry.' })
    )
    ;(useFlushRetryQueue as Mock).mockReturnValue({ mutate, isPending: false })
    render(<RetryQueuePanel />)

    await userEvent.click(screen.getByRole('button', { name: 'Clear queue' }))
    await userEvent.click(await screen.findByRole('button', { name: 'Discard' }))

    expect(notify.success).toHaveBeenCalledWith('Cleared 1 queued retry.')
  })
})
