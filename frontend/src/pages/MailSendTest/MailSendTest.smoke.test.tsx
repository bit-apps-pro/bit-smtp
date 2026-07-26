import { renderWithProviders } from '@config/test-utils'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import MailSendTest from './MailSendTest'

vi.mock('@common/helpers/request', () => ({
  default: vi.fn().mockResolvedValue({
    status: 'success',
    code: 'SUCCESS',
    data: [],
    message: 'Mail sent successfully'
  })
}))

describe('MailSendTest page', () => {
  it('renders without throwing after the frontend upgrade', () => {
    expect(() => renderWithProviders(<MailSendTest />)).not.toThrow()
  })

  it('keeps the API send result visible after a successful test', async () => {
    renderWithProviders(<MailSendTest />)

    await userEvent.type(screen.getByRole('textbox', { name: 'To' }), 'recipient@example.com')
    await userEvent.click(screen.getByRole('button', { name: 'Send Test Email' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Mail sent successfully')
  })
})
