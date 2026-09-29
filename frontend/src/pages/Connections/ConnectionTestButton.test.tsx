import { readOnboardingFlags } from '@components/Onboarding'
import notify from '@components/Toaster/Toaster'
import { type Connection } from '@pages/Connections/types'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { type Mock, beforeEach, describe, expect, it, vi } from 'vitest'
import ConnectionTestButton, { ConnectionTestOutcome } from './ConnectionTestButton'
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

const TO = 'test@example.com'

/** Renders the button over a mocked mutation that resolves with `result`, and clicks it. */
async function clickAndDeliver(result: Omit<ConnectionTestResult, 'to'>) {
  const mutate = vi.fn((_payload, opts) => opts.onSuccess({ to: TO, ...result }))
  ;(useTestConnection as Mock).mockReturnValue({ mutate, isPending: false })

  render(<ConnectionTestButton prepare={async () => connection} defaultRecipient={TO} />)
  await userEvent.click(screen.getByRole('button', { name: 'Send test email' }))
}

describe('ConnectionTestButton', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('sends the prepared connection to the default recipient', async () => {
    const mutate = vi.fn()
    ;(useTestConnection as Mock).mockReturnValue({ mutate, isPending: false })

    render(<ConnectionTestButton prepare={async () => connection} defaultRecipient={TO} />)

    expect(screen.getByLabelText('Send test to')).toHaveValue(TO)
    await userEvent.click(screen.getByRole('button', { name: 'Send test email' }))

    expect(mutate).toHaveBeenCalledWith({ connection, to: TO }, expect.anything())
  })

  it('records onboarding progress when the test email is sent', async () => {
    localStorage.clear()
    await clickAndDeliver({ ok: true, debug: [], delivery: null })
    expect(readOnboardingFlags().testEmailSent).toBe(true)
  })

  it('does not record onboarding progress when the test fails', async () => {
    localStorage.clear()
    await clickAndDeliver({ ok: false, debug: [], delivery: null })
    expect(readOnboardingFlags().testEmailSent).toBe(false)
  })

  it('sends to a recipient the user typed instead of the default', async () => {
    const mutate = vi.fn()
    ;(useTestConnection as Mock).mockReturnValue({ mutate, isPending: false })

    render(<ConnectionTestButton prepare={async () => connection} defaultRecipient={TO} />)

    await userEvent.clear(screen.getByLabelText('Send test to'))
    await userEvent.type(screen.getByLabelText('Send test to'), 'me@site.test')
    await userEvent.click(screen.getByRole('button', { name: 'Send test email' }))

    expect(mutate).toHaveBeenCalledWith({ connection, to: 'me@site.test' }, expect.anything())
  })

  it('does not send when the form is invalid', async () => {
    const mutate = vi.fn()
    ;(useTestConnection as Mock).mockReturnValue({ mutate, isPending: false })

    render(<ConnectionTestButton prepare={async () => undefined} defaultRecipient={TO} />)
    await userEvent.click(screen.getByRole('button', { name: 'Send test email' }))

    expect(mutate).not.toHaveBeenCalled()
  })

  it('asks for a recipient instead of sending when none is set', async () => {
    const mutate = vi.fn()
    const prepare = vi.fn(async () => connection)
    ;(useTestConnection as Mock).mockReturnValue({ mutate, isPending: false })

    render(<ConnectionTestButton prepare={prepare} defaultRecipient="" />)
    await userEvent.click(screen.getByRole('button', { name: 'Send test email' }))

    expect(screen.getByText('Enter the email address that should receive the test.')).toBeInTheDocument()
    expect(screen.getByLabelText('Send test to')).toHaveAttribute('aria-invalid', 'true')
    expect(prepare).not.toHaveBeenCalled()
    expect(mutate).not.toHaveBeenCalled()
  })

  it('reports the result to the parent via onResult', async () => {
    const onResult = vi.fn()
    const mutate = vi.fn((_payload, opts) => opts.onSuccess({ ok: true, to: TO, debug: [] }))
    ;(useTestConnection as Mock).mockReturnValue({ mutate, isPending: false })

    render(
      <ConnectionTestButton prepare={async () => connection} defaultRecipient={TO} onResult={onResult} />
    )
    await userEvent.click(screen.getByRole('button', { name: 'Send test email' }))

    expect(onResult).toHaveBeenCalledWith({ ok: true, to: TO, debug: [] })
  })

  it('toasts delivery to the recipient', async () => {
    await clickAndDeliver({ ok: true, debug: {}, delivery: { state: 'delivered', detail: '' } })

    expect(notify.success).toHaveBeenCalledWith(`Delivered to ${TO}.`)
  })

  it('warns with the provider detail when the message is deferred', async () => {
    await clickAndDeliver({
      ok: false,
      debug: {},
      delivery: { state: 'deferred', detail: 'Mailbox full' }
    })

    expect(notify.warning).toHaveBeenCalledWith('Mailbox full')
  })

  it('toasts the failure type label when the provider rejected the login', async () => {
    await clickAndDeliver({ ok: false, debug: [], error: '535 auth failed', failureClass: 'auth' })

    expect(notify.error).toHaveBeenCalledWith('Login or API key rejected')
  })
})

describe('ConnectionTestOutcome', () => {
  it('says what a successful test proves, plus the provider caveat', () => {
    render(
      <ConnectionTestOutcome
        result={{ ok: true, to: TO, debug: {}, delivery: null }}
        providerLabel="Brevo"
        testCaveat="Sandbox accounts only reach verified addresses."
      />
    )

    expect(screen.getByRole('status')).toHaveTextContent(`Test email sent to ${TO}.`)
    expect(screen.getByRole('status')).toHaveTextContent(
      'Brevo accepted your login details and sender address.'
    )
    expect(screen.getByRole('status')).toHaveTextContent(
      'Sandbox accounts only reach verified addresses.'
    )
  })

  it('keeps the raw provider error and debug log under collapsed technical details', async () => {
    render(
      <ConnectionTestOutcome
        result={{
          ok: false,
          to: TO,
          debug: ['SMTP connect() failed'],
          error: 'Could not connect',
          failureClass: 'transient'
        }}
        providerLabel="Other SMTP"
      />
    )

    expect(screen.getByText('Temporary server or network problem')).toBeInTheDocument()
    expect(screen.queryByText('Could not connect')).not.toBeInTheDocument()

    await userEvent.click(screen.getByText('Technical details'))

    expect(screen.getByText('Could not connect')).toBeInTheDocument()
    expect(screen.getByText('SMTP connect() failed')).toBeInTheDocument()
  })

  it('lists validation messages that no visible field can show', () => {
    render(
      <ConnectionTestOutcome
        result={{
          ok: false,
          to: TO,
          debug: [],
          fieldErrors: {
            client_id: 'Client ID is required.',
            refresh_token: 'Google account is not connected.'
          }
        }}
        providerLabel="Gmail"
        visibleFieldKeys={['client_id']}
      />
    )

    expect(screen.getByRole('status')).toHaveTextContent('Google account is not connected.')
    expect(screen.getByRole('status')).not.toHaveTextContent('Client ID is required.')
  })

  it('renders nothing when there is no result yet', () => {
    const { container } = render(<ConnectionTestOutcome providerLabel="Brevo" />)

    expect(container).toBeEmptyDOMElement()
  })
})
