import request from '@common/helpers/request'
import notify from '@components/Toaster/Toaster'
import { renderWithProviders } from '@config/test-utils'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Form } from 'antd'
import { afterEach, describe, expect, it, vi } from 'vitest'
import UsageSharingField from './UsageSharingField'

vi.mock('@common/helpers/request', () => ({ default: vi.fn() }))
vi.mock('@components/Toaster/Toaster', () => ({ default: { error: vi.fn(), success: vi.fn() } }))

const SWITCH_NAME = 'Share usage data with the Bit SMTP team'

/** Serve a stored sharing state and echo each consent POST back as the new state. */
function mockBackend(allowed: boolean) {
  vi.mocked(request).mockImplementation((async ({
    action,
    data
  }: {
    action: string
    data?: { allow: boolean }
  }) => ({
    status: 'success',
    code: 'SUCCESS',
    message: undefined,
    data: { answered: true, allowed: action === 'telemetry/consent' ? data?.allow === true : allowed }
  })) as never)
}

/** Render the field inside a Form, as the Privacy tab does. */
function renderField() {
  return renderWithProviders(
    <Form>
      <UsageSharingField />
    </Form>
  )
}

describe('UsageSharingField', () => {
  afterEach(() => {
    vi.mocked(request).mockReset()
    vi.mocked(notify.success).mockClear()
  })

  it('reflects the stored choice', async () => {
    mockBackend(true)
    renderField()

    await waitFor(() => expect(screen.getByRole('switch', { name: SWITCH_NAME })).toBeChecked())
  })

  it('turns sharing off right away when switched off', async () => {
    mockBackend(true)
    renderField()
    const toggle = screen.getByRole('switch', { name: SWITCH_NAME })
    await waitFor(() => expect(toggle).toBeEnabled())

    await userEvent.click(toggle)

    expect(request).toHaveBeenCalledWith({ action: 'telemetry/consent', data: { allow: false } })
    await waitFor(() => expect(toggle).not.toBeChecked())
    expect(notify.success).toHaveBeenCalledWith('Usage sharing is off.')
  })

  it('stays disabled when the stored choice cannot be loaded', async () => {
    vi.mocked(request).mockRejectedValue(new Error('offline'))
    renderField()

    await waitFor(() => expect(request).toHaveBeenCalled())
    expect(screen.getByRole('switch', { name: SWITCH_NAME })).toBeDisabled()
  })
})
