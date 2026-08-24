import request from '@common/helpers/request'
import { renderWithProviders } from '@config/test-utils'
import useMailSettings from '@pages/Connections/data/useMailSettings'
import useMailSources from '@pages/Routing/data/useMailSources'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { type Mock, afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import Logs from './Logs'

vi.mock('@common/helpers/request', () => ({
  default: vi.fn().mockResolvedValue({ status: 'success', code: 'SUCCESS', data: {} })
}))
vi.mock('@pages/Connections/data/useMailSettings', () => ({ default: vi.fn() }))
vi.mock('@pages/Routing/data/useMailSources', () => ({ default: vi.fn() }))

const CONNECTIONS = [
  { id: 'conn_1', name: 'Primary SMTP' },
  { id: 'conn_2', name: 'Backup SMTP' }
]
const SOURCES = [{ value: 'woocommerce', label: 'WooCommerce' }]

/** Selects an antd combobox option by its accessible combobox name and the option's visible text. */
async function selectOption(
  user: ReturnType<typeof userEvent.setup>,
  comboboxName: string,
  optionText: string
) {
  await user.click(screen.getByRole('combobox', { name: comboboxName }))
  await user.click(await screen.findByTitle(optionText))
}

/** Last `logs/all` request payload sent to the mocked `request` helper. */
function lastLogsRequestData() {
  return (request as Mock).mock.calls.at(-1)?.[0]?.data
}

describe('Logs page URL-seeded filters', () => {
  beforeEach(() => {
    ;(request as Mock).mockClear()
    ;(useMailSettings as Mock).mockReturnValue({ data: { connections: CONNECTIONS }, isPending: false })
    ;(useMailSources as Mock).mockReturnValue({ data: SOURCES, isPending: false })
    window.location.hash = '#/logs?delivery_status=bounced'
  })

  afterEach(() => {
    window.location.hash = ''
  })

  it('seeds a removable chip from the delivery_status URL param and sends it to logs/all', async () => {
    renderWithProviders(<Logs />)

    expect(await screen.findByText('Delivery: Bounced')).toBeInTheDocument()
    await waitFor(() => {
      const lastCall = (request as Mock).mock.calls.at(-1)?.[0]
      expect(lastCall).toMatchObject({ action: 'logs/all', data: { delivery_status: 'bounced' } })
    })
  })

  it('closing the chip clears the filter from state, the URL, and the next logs/all request', async () => {
    const user = userEvent.setup()
    const { container } = renderWithProviders(<Logs />)

    expect(await screen.findByText('Delivery: Bounced')).toBeInTheDocument()

    const closeIcon = container.querySelector('.ant-tag-close-icon') as HTMLElement
    await user.click(closeIcon)

    expect(screen.queryByText('Delivery: Bounced')).not.toBeInTheDocument()
    expect(window.location.hash).not.toContain('delivery_status')
    await waitFor(() => {
      expect(lastLogsRequestData()?.delivery_status).toBeUndefined()
    })
  })
})

describe('Logs page filter bar controls', () => {
  beforeEach(() => {
    ;(request as Mock).mockClear()
    ;(useMailSettings as Mock).mockReturnValue({ data: { connections: CONNECTIONS }, isPending: false })
    ;(useMailSources as Mock).mockReturnValue({ data: SOURCES, isPending: false })
    window.location.hash = '#/logs'
  })

  afterEach(() => {
    window.location.hash = ''
  })

  it('selecting a send status sets status, the URL, and shows a friendly "Send status:" chip', async () => {
    const user = userEvent.setup()
    renderWithProviders(<Logs />)

    await selectOption(user, 'Send status', 'Failed')

    expect(await screen.findByText('Send status: Failed')).toBeInTheDocument()
    expect(window.location.hash).toContain('status=failed')
    await waitFor(() => expect(lastLogsRequestData()?.status).toBe('failed'))
  })

  it('selecting a delivery status sets delivery_status, the URL, and shows a "Delivery:" chip', async () => {
    const user = userEvent.setup()
    renderWithProviders(<Logs />)

    await selectOption(user, 'Delivery status', 'Delivered')

    expect(await screen.findByText('Delivery: Delivered')).toBeInTheDocument()
    expect(window.location.hash).toContain('delivery_status=delivered')
    await waitFor(() => expect(lastLogsRequestData()?.delivery_status).toBe('delivered'))
  })

  it('does not offer "Pending" as a delivery-status option (it is a NULL-rendered UI state, never a stored value)', async () => {
    const user = userEvent.setup()
    renderWithProviders(<Logs />)

    await user.click(screen.getByRole('combobox', { name: 'Delivery status' }))

    expect(await screen.findByTitle('Delivered')).toBeInTheDocument()
    expect(screen.queryByTitle('Pending')).not.toBeInTheDocument()
  })

  it('selecting a connection sets connection_id and shows the connection name on the chip', async () => {
    const user = userEvent.setup()
    renderWithProviders(<Logs />)

    await selectOption(user, 'Connection', 'Backup SMTP')

    expect(await screen.findByText('Connection: Backup SMTP')).toBeInTheDocument()
    expect(window.location.hash).toContain('connection_id=conn_2')
    await waitFor(() => expect(lastLogsRequestData()?.connection_id).toBe('conn_2'))
  })

  it('selecting a source sets source_plugin and shows its label on the chip', async () => {
    const user = userEvent.setup()
    renderWithProviders(<Logs />)

    await selectOption(user, 'Source', 'WooCommerce')

    expect(await screen.findByText('Source: WooCommerce')).toBeInTheDocument()
    expect(window.location.hash).toContain('source_plugin=woocommerce')
    await waitFor(() => expect(lastLogsRequestData()?.source_plugin).toBe('woocommerce'))
  })

  it('picking a date range sets date_from and date_to as YYYY-MM-DD', async () => {
    const user = userEvent.setup()
    renderWithProviders(<Logs />)

    await user.click(screen.getByPlaceholderText('From date'))
    await user.type(screen.getByPlaceholderText('From date'), '2026-01-01{Enter}')
    await user.type(screen.getByPlaceholderText('To date'), '2026-01-31{Enter}')

    await waitFor(() => {
      expect(lastLogsRequestData()?.date_from).toBe('2026-01-01')
      expect(lastLogsRequestData()?.date_to).toBe('2026-01-31')
    })
    expect(window.location.hash).toContain('date_from=2026-01-01')
    expect(window.location.hash).toContain('date_to=2026-01-31')
  })

  it('closing the connection chip clears the control, the URL, and the next logs/all request', async () => {
    const user = userEvent.setup()
    const { container } = renderWithProviders(<Logs />)

    await selectOption(user, 'Connection', 'Primary SMTP')
    expect(await screen.findByText('Connection: Primary SMTP')).toBeInTheDocument()

    const closeIcon = container.querySelector('.ant-tag-close-icon') as HTMLElement
    await user.click(closeIcon)

    expect(screen.queryByText('Connection: Primary SMTP')).not.toBeInTheDocument()
    expect(window.location.hash).not.toContain('connection_id')
    await waitFor(() => expect(lastLogsRequestData()?.connection_id).toBeUndefined())
  })
})
