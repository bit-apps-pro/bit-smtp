import { renderWithProviders } from '@config/test-utils'
import useMailSettings from '@pages/Connections/data/useMailSettings'
import useUpdateSettings from '@pages/Connections/data/useUpdateSettings'
import { type MailSettings } from '@pages/Connections/types'
import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { type Mock, beforeEach, describe, expect, it, vi } from 'vitest'
import RoutingRulesPage from './RoutingRulesPage'

vi.mock('@pages/Connections/data/useMailSettings', () => ({ default: vi.fn() }))
vi.mock('@pages/Connections/data/useUpdateSettings', () => ({ default: vi.fn() }))

const settings: MailSettings = {
  schema_version: 2,
  enabled: true,
  default_connection_id: 'conn_1',
  fallback_connection_ids: [],
  connections: [
    {
      id: 'conn_1',
      provider: 'other_smtp',
      kind: 'smtp',
      name: 'Primary SMTP',
      enabled: true,
      fromEmail: 'a@b.c',
      fromName: 'A',
      replyToEmail: '',
      settings: {},
      credentials: {}
    },
    {
      id: 'conn_2',
      provider: 'other_smtp',
      kind: 'smtp',
      name: 'Backup SMTP',
      enabled: true,
      fromEmail: 'd@e.f',
      fromName: 'D',
      replyToEmail: '',
      settings: {},
      credentials: {}
    }
  ],
  features: {
    routing: [
      {
        connectionId: 'conn_2',
        conditions: [{ field: 'subject', operator: 'contains', value: 'invoice' }]
      }
    ]
  }
}

describe('RoutingRulesPage', () => {
  const updateSettingsMutate = vi.fn()

  beforeEach(() => {
    updateSettingsMutate.mockClear()
    ;(useMailSettings as Mock).mockReturnValue({ data: settings, isPending: false })
    ;(useUpdateSettings as Mock).mockReturnValue({ mutate: updateSettingsMutate, isPending: false })
  })

  it('shows a loading spinner while settings are pending', () => {
    ;(useMailSettings as Mock).mockReturnValue({ data: undefined, isPending: true })

    const { container } = renderWithProviders(<RoutingRulesPage />)

    expect(container.querySelector('.ant-spin')).toBeInTheDocument()
  })

  it('renders the existing rules from features.routing', () => {
    renderWithProviders(<RoutingRulesPage />)

    expect(screen.getByText('Backup SMTP')).toBeInTheDocument()
    expect(screen.getByLabelText('Value')).toHaveValue('invoice')
  })

  it('adds a rule row when Add rule is clicked', async () => {
    renderWithProviders(<RoutingRulesPage />)

    expect(screen.getAllByRole('combobox', { name: 'Target connection' })).toHaveLength(1)

    await userEvent.click(screen.getByRole('button', { name: /Add rule/ }))

    expect(screen.getAllByRole('combobox', { name: 'Target connection' })).toHaveLength(2)
  })

  it('saves features.routing with the connectionId and condition shape on Save', async () => {
    renderWithProviders(<RoutingRulesPage />)

    await userEvent.click(screen.getByRole('button', { name: /Save/ }))

    expect(updateSettingsMutate).toHaveBeenCalled()
    const [payload] = updateSettingsMutate.mock.calls[0]
    expect(payload).toEqual({
      features: {
        routing: [
          {
            connectionId: 'conn_2',
            conditions: [{ field: 'subject', operator: 'contains', value: 'invoice' }]
          }
        ]
      }
    })
  })

  it('posts an updated condition value after editing it', async () => {
    renderWithProviders(<RoutingRulesPage />)

    await userEvent.clear(screen.getByLabelText('Value'))
    await userEvent.type(screen.getByLabelText('Value'), 'receipt')
    await userEvent.click(screen.getByRole('button', { name: /Save/ }))

    const [payload] = updateSettingsMutate.mock.calls[0]
    expect(payload).toEqual({
      features: {
        routing: [
          {
            connectionId: 'conn_2',
            conditions: [{ field: 'subject', operator: 'contains', value: 'receipt' }]
          }
        ]
      }
    })
  })

  it('removes a rule from the saved payload when it is deleted', async () => {
    renderWithProviders(<RoutingRulesPage />)

    const ruleCard = screen.getByText('Backup SMTP').closest('.ant-card') as HTMLElement
    await userEvent.click(within(ruleCard).getByRole('button', { name: /Delete rule/ }))
    await userEvent.click(await screen.findByRole('button', { name: 'OK' }))
    await userEvent.click(screen.getByRole('button', { name: /Save/ }))

    const [payload] = updateSettingsMutate.mock.calls[0]
    expect(payload).toEqual({ features: { routing: [] } })
  })

  it('builds a new rule with one empty condition and no connection when added and saved', async () => {
    renderWithProviders(<RoutingRulesPage />)

    await userEvent.click(screen.getByRole('button', { name: /Add rule/ }))
    await userEvent.click(screen.getByRole('button', { name: /Save/ }))

    const [payload] = updateSettingsMutate.mock.calls[0]
    expect(payload).toEqual({
      features: {
        routing: [
          {
            connectionId: 'conn_2',
            conditions: [{ field: 'subject', operator: 'contains', value: 'invoice' }]
          },
          { connectionId: '', conditions: [{ field: 'recipient', operator: 'equals', value: '' }] }
        ]
      }
    })
  })
})
