import request from '@common/helpers/request'
import { renderWithProviders } from '@config/test-utils'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { notification } from 'antd'
import { type Mock, beforeEach, describe, expect, it, vi } from 'vitest'
import Logs from './Logs'

vi.mock('@common/helpers/request', () => ({ default: vi.fn() }))

const LOGS_RESPONSE = {
  status: 'success',
  code: 'SUCCESS',
  data: {
    logs: [1, 2].map(id => ({
      id,
      status: 1,
      subject: `Subject ${id}`,
      to_addr: [`user${id}@example.org`],
      retry_count: 0,
      connection: 'Primary SMTP',
      created_at: ''
    })),
    count: 2,
    current: 1,
    pages: 1
  }
}

/** Mocks `request`: logs/all returns two rows, every mutation succeeds with `message`. */
function mockRequests() {
  ;(request as Mock).mockImplementation(({ action }: { action: string }) =>
    Promise.resolve(
      action === 'logs/all'
        ? LOGS_RESPONSE
        : { status: 'success', code: 'SUCCESS', message: 'Mail resent', data: {} }
    )
  )
}

/** Whether `request` was called with the given action. */
function wasCalledWith(action: string): boolean {
  return (request as Mock).mock.calls.some(([args]) => args?.action === action)
}

/** Selects every row via the header checkbox. */
async function selectAll(user: ReturnType<typeof userEvent.setup>) {
  await screen.findByText('Subject 1')
  await user.click(screen.getAllByRole('checkbox')[0])
}

describe('Logs bulk actions', () => {
  beforeEach(() => {
    ;(request as Mock).mockReset()
    mockRequests()
  })

  it('asks before resending and reports how many emails were resent', async () => {
    const success = vi.spyOn(notification, 'success')
    const user = userEvent.setup()
    renderWithProviders(<Logs />)
    await selectAll(user)

    await user.click(screen.getByRole('button', { name: 'Resend' }))
    const confirm = await screen.findByRole('tooltip')
    expect(within(confirm).getByText('Resend 2 emails?')).toBeInTheDocument()
    expect(wasCalledWith('mail/resend')).toBe(false)

    await user.click(within(confirm).getByRole('button', { name: 'Resend 2 emails' }))

    await waitFor(() => expect(wasCalledWith('mail/resend')).toBe(true))
    await waitFor(() =>
      expect(success).toHaveBeenCalledWith(expect.objectContaining({ message: '2 emails resent.' }))
    )
  })

  it('asks before deleting and never deletes when cancelled', async () => {
    const user = userEvent.setup()
    renderWithProviders(<Logs />)
    await selectAll(user)

    await user.click(screen.getByRole('button', { name: 'Delete' }))
    const confirm = await screen.findByRole('tooltip')
    expect(within(confirm).getByText('Delete 2 log entries?')).toBeInTheDocument()
    expect(within(confirm).getByText("This can't be undone.")).toBeInTheDocument()

    await user.click(within(confirm).getByRole('button', { name: 'Cancel' }))
    expect(wasCalledWith('logs/delete')).toBe(false)
  })
})
