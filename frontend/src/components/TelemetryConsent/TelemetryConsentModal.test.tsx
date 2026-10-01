import { DOCS } from '@common/docs'
import request from '@common/helpers/request'
import notify from '@components/Toaster/Toaster'
import { renderWithProviders } from '@config/test-utils'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import TelemetryConsentModal from './TelemetryConsentModal'

vi.mock('@common/helpers/request', () => ({ default: vi.fn() }))
vi.mock('@components/Toaster/Toaster', () => ({ default: { error: vi.fn(), success: vi.fn() } }))

const DIALOG_NAME = 'Help us make Bit SMTP better?'

/** Answer the status GET with the given stored answer and every consent POST with the given envelope status. */
function mockBackend(answered: boolean, consentStatus: 'success' | 'error' = 'success') {
  vi.mocked(request).mockImplementation((async ({
    action,
    data
  }: {
    action: string
    data?: { allow: boolean }
  }) => {
    if (action === 'telemetry/status') {
      return {
        status: 'success',
        code: 'SUCCESS',
        message: undefined,
        data: { answered, allowed: false }
      }
    }
    return {
      status: consentStatus,
      code: consentStatus === 'success' ? 'SUCCESS' : 'ERROR',
      message: undefined,
      data: { answered: true, allowed: data?.allow === true }
    }
  }) as never)
}

/** The consent POST calls made so far. */
function consentCalls() {
  return vi.mocked(request).mock.calls.filter(([options]) => options.action === 'telemetry/consent')
}

describe('TelemetryConsentModal', () => {
  afterEach(() => {
    vi.mocked(request).mockReset()
    vi.mocked(notify.error).mockClear()
  })

  it('asks a site that has not answered yet', async () => {
    mockBackend(false)
    renderWithProviders(<TelemetryConsentModal />)

    expect(await screen.findByRole('dialog', { name: DIALOG_NAME })).toBeInTheDocument()
  })

  it('stays hidden once the site has answered', async () => {
    mockBackend(true)
    renderWithProviders(<TelemetryConsentModal />)

    await waitFor(() => expect(request).toHaveBeenCalled())
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('stays hidden when the status cannot be loaded', async () => {
    vi.mocked(request).mockRejectedValue(new Error('offline'))
    renderWithProviders(<TelemetryConsentModal />)

    await waitFor(() => expect(request).toHaveBeenCalled())
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('says plainly what is and is not shared, with the privacy policy', async () => {
    mockBackend(false)
    renderWithProviders(<TelemetryConsentModal />)

    await screen.findByRole('dialog', { name: DIALOG_NAME })
    expect(screen.getByText(/Nothing is sent unless you choose/)).toBeInTheDocument()
    expect(screen.getByText('What your emails say')).toBeInTheDocument()
    expect(screen.getByText('Who you send email to')).toBeInTheDocument()
    expect(screen.getByText(/passwords, API keys/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /privacy policy/i })).toHaveAttribute(
      'href',
      DOCS.privacyPolicy
    )
  })

  it('shares only after "Yes, share" and never asks again', async () => {
    mockBackend(false)
    renderWithProviders(<TelemetryConsentModal />)

    await userEvent.click(await screen.findByRole('button', { name: 'Yes, share' }))

    expect(consentCalls()).toEqual([[{ action: 'telemetry/consent', data: { allow: true } }]])
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it.each(['Yes, share', 'No thanks'])('does not ask again after %s on a later visit', async label => {
    let answered = false
    let allowed = false
    vi.mocked(request).mockImplementation((async ({
      action,
      data
    }: {
      action: string
      data?: { allow: boolean }
    }) => {
      if (action === 'telemetry/consent') {
        answered = true
        allowed = data?.allow === true
      }
      return { status: 'success', code: 'SUCCESS', data: { answered, allowed } }
    }) as never)
    const firstVisit = renderWithProviders(<TelemetryConsentModal />)
    await userEvent.click(await screen.findByRole('button', { name: label }))
    await waitFor(() => expect(answered).toBe(true))
    firstVisit.unmount()
    vi.mocked(request).mockClear()

    renderWithProviders(<TelemetryConsentModal />)

    await waitFor(() =>
      expect(request).toHaveBeenCalledWith({ action: 'telemetry/status', method: 'GET' })
    )
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(allowed).toBe(label === 'Yes, share')
  })

  it.each([
    ['"No thanks"', () => userEvent.click(screen.getByRole('button', { name: 'No thanks' }))],
    ['the close button', () => userEvent.click(screen.getByRole('button', { name: 'Close' }))],
    ['Escape', () => fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape', keyCode: 27 })]
  ])('records a "no" from %s and closes', async (_label, dismiss) => {
    mockBackend(false)
    renderWithProviders(<TelemetryConsentModal />)
    await screen.findByRole('dialog', { name: DIALOG_NAME })

    await dismiss()

    expect(consentCalls()).toEqual([[{ action: 'telemetry/consent', data: { allow: false } }]])
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('stays closed for this visit when saving the answer fails', async () => {
    mockBackend(false, 'error')
    renderWithProviders(<TelemetryConsentModal />)

    await userEvent.click(await screen.findByRole('button', { name: 'No thanks' }))

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    await waitFor(() =>
      expect(notify.error).toHaveBeenCalledWith(
        'Couldn’t confirm your choice',
        'Check Settings › Privacy & data to see whether sharing is on.'
      )
    )
  })
})
