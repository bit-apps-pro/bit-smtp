import request from '@common/helpers/request'
import { readOnboardingFlags } from '@components/Onboarding'
import { renderWithProviders } from '@config/test-utils'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import MailSendTest from './MailSendTest'

vi.mock('@common/helpers/request', () => ({ default: vi.fn() }))

const SETTINGS = {
  schema_version: 1,
  enabled: true,
  default_connection_id: 'primary',
  fallback_connection_ids: ['backup'],
  connections: [
    { id: 'primary', provider: 'smtp', name: 'Primary', enabled: true },
    { id: 'backup', provider: 'brevo', name: 'Brevo', enabled: true },
    { id: 'off', provider: 'resend', name: 'Old', enabled: false }
  ],
  features: {}
}

/** Answer the settings query and the send-test call with the given send response. */
function serve(sendResponse: Record<string, unknown>) {
  vi.mocked(request).mockImplementation(async ({ action }) =>
    action === 'mail/settings'
      ? { status: 'success', code: 'SUCCESS', message: undefined, data: { settings: SETTINGS } as never }
      : (sendResponse as never)
  )
}

/** Fill the recipient and submit the form. */
async function sendTest() {
  await userEvent.type(screen.getByRole('textbox', { name: 'Send to' }), 'recipient@example.com')
  await userEvent.click(screen.getByRole('button', { name: 'Send test email' }))
}

/** The FormData body the page sent to the send-test endpoint. */
function sentBody(): FormData {
  const call = vi.mocked(request).mock.calls.find(([options]) => options.action === 'mail/send-test')
  return call?.[0].data as FormData
}

describe('MailSendTest page', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  afterEach(() => {
    vi.mocked(request).mockReset()
  })

  it('reports which connection sent the test and records onboarding progress', async () => {
    serve({
      status: 'success',
      code: 'SUCCESS',
      message: 'Mail sent successfully',
      data: {
        attempts: [{ connection: 'Primary', status: 'sent', error: null }],
        debug: [],
        sent_via: 'Primary',
        failed_over: false
      }
    })
    renderWithProviders(<MailSendTest />)

    await sendTest()

    expect(
      await screen.findByText('Sent by Primary. Check the inbox to make sure it arrived.')
    ).toBeInTheDocument()
    expect(sentBody().has('connection_id')).toBe(false)
    expect(readOnboardingFlags().testEmailSent).toBe(true)
    expect(screen.getByRole('link', { name: /^Leave a review/ })).toBeInTheDocument()
  })

  it('warns when a backup connection delivered the test', async () => {
    serve({
      status: 'success',
      code: 'SUCCESS',
      message: 'Mail sent successfully',
      data: {
        attempts: [
          { connection: 'Primary', status: 'failed', error: 'Authentication failed' },
          { connection: 'Brevo', status: 'sent', error: null }
        ],
        debug: [],
        sent_via: 'Brevo',
        failed_over: true
      }
    })
    renderWithProviders(<MailSendTest />)

    await sendTest()

    expect(await screen.findByText('Sent via failover')).toBeInTheDocument()
    expect(screen.getByText(/Delivered by Brevo/)).toBeInTheDocument()
    expect(screen.getByText(': Authentication failed', { exact: false })).toBeInTheDocument()
  })

  it('sends through the chosen connection and lists turned-off ones as unavailable', async () => {
    serve({
      status: 'error',
      code: 'ERROR',
      message: 'The selected connection is unavailable',
      data: []
    })
    renderWithProviders(<MailSendTest />)
    await waitFor(() =>
      expect(request).toHaveBeenCalledWith(expect.objectContaining({ action: 'mail/settings' }))
    )

    await userEvent.click(screen.getByRole('combobox', { name: 'Send using' }))
    expect(await screen.findByTitle('Old (turned off)')).toHaveClass('ant-select-item-option-disabled')
    await userEvent.click(screen.getByTitle('Brevo'))
    await sendTest()

    expect(await screen.findByText('The selected connection is unavailable')).toBeInTheDocument()
    expect(sentBody().get('connection_id')).toBe('backup')
    expect(readOnboardingFlags().testEmailSent).toBe(false)
  })

  it('shows translated validation messages', async () => {
    serve({})
    renderWithProviders(<MailSendTest />)

    await userEvent.click(screen.getByRole('button', { name: 'Send test email' }))

    expect(await screen.findByText('Enter the email address to send the test to.')).toBeInTheDocument()
  })
})
