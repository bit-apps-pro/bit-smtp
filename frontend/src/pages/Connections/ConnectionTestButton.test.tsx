import { type Connection } from '@pages/Connections/types'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { type Mock, beforeEach, describe, expect, it, vi } from 'vitest'
import ConnectionTestButton from './ConnectionTestButton'
import useTestConnection from './data/useTestConnection'

vi.mock('./data/useTestConnection', () => ({ default: vi.fn() }))

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
})
