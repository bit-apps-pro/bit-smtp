import notify from '@components/Toaster/Toaster'
import { type Connection } from '@pages/Connections/types'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { type Mock, beforeEach, describe, expect, it, vi } from 'vitest'
import ConnectionTestButton from './ConnectionTestButton'
import useTestConnection, { type ConnectionTestResult } from './data/useTestConnection'

vi.mock('./data/useTestConnection', () => ({ default: vi.fn() }))
vi.mock('@components/Toaster/Toaster', () => ({
  default: { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() }
}))

const connection: Connection = {
  id: 'conn_a',
  provider: 'other_smtp',
  kind: 'smtp',
  name: 'A',
  enabled: true,
  fromEmail: 'from@example.com',
  fromName: 'From',
  replyToEmail: '',
  settings: { host: 'smtp.example.com', port: 587 },
  credentials: { password: { source: 'database', value: '********' } }
}

async function clickAndDeliver(result: ConnectionTestResult) {
  const mutate = vi.fn((_payload, opts) => opts.onSuccess(result))
  ;(useTestConnection as Mock).mockReturnValue({ mutate, isPending: false, data: undefined })

  render(<ConnectionTestButton getConnection={() => connection} to="test@example.com" />)
  await userEvent.click(screen.getByRole('button', { name: /test connection/i }))
}

describe('ConnectionTestButton', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('shows the returned debug output', () => {
    ;(useTestConnection as Mock).mockReturnValue({
      mutate: vi.fn(),
      isPending: false,
      data: { ok: true, debug: ['Connected', 'Message sent'] }
    })

    render(<ConnectionTestButton getConnection={() => connection} to="test@example.com" />)

    expect(screen.getByText('Connected')).toBeInTheDocument()
    expect(screen.getByText('Message sent')).toBeInTheDocument()
  })

  it('tests the current connection values on click', async () => {
    const mutate = vi.fn()
    ;(useTestConnection as Mock).mockReturnValue({ mutate, isPending: false, data: undefined })

    render(<ConnectionTestButton getConnection={() => connection} to="test@example.com" />)

    await userEvent.click(screen.getByRole('button', { name: /test connection/i }))

    expect(mutate).toHaveBeenCalledWith({ connection, to: 'test@example.com' }, expect.anything())
  })

  it('shows a success toast when the provider reports the message delivered', async () => {
    await clickAndDeliver({ ok: true, debug: {}, delivery: { state: 'delivered', detail: '' } })

    expect(notify.success).toHaveBeenCalledWith('Delivered')
  })

  it('warns with the provider detail when the message is deferred', async () => {
    await clickAndDeliver({
      ok: false,
      debug: {},
      delivery: { state: 'deferred', detail: 'mailbox full' }
    })

    expect(notify.warning).toHaveBeenCalledWith('mailbox full')
  })

  it('errors with the provider detail when the message is blocked', async () => {
    await clickAndDeliver({
      ok: false,
      debug: {},
      delivery: { state: 'blocked', detail: 'spam suspected' }
    })

    expect(notify.error).toHaveBeenCalledWith('spam suspected')
  })

  it('stays silent on success when no delivery status is available', async () => {
    await clickAndDeliver({ ok: true, debug: {}, delivery: null })

    expect(notify.success).not.toHaveBeenCalled()
    expect(notify.error).not.toHaveBeenCalled()
    expect(notify.warning).not.toHaveBeenCalled()
  })
})
