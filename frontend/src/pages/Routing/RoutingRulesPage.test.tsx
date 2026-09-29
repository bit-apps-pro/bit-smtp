import notify from '@components/Toaster/Toaster'
import { renderWithProviders } from '@config/test-utils'
import type * as DndKitCore from '@dnd-kit/core'
import type * as DndKitSortable from '@dnd-kit/sortable'
import useMailSettings from '@pages/Connections/data/useMailSettings'
import useUpdateSettings from '@pages/Connections/data/useUpdateSettings'
import { type MailSettings } from '@pages/Connections/types'
import useMailSources from '@pages/Routing/data/useMailSources'
import { act, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { type Mock, beforeEach, describe, expect, it, vi } from 'vitest'
import RoutingRulesPage from './RoutingRulesPage'

vi.mock('@pages/Connections/data/useMailSettings', () => ({ default: vi.fn() }))
vi.mock('@pages/Connections/data/useUpdateSettings', () => ({ default: vi.fn() }))
vi.mock('@pages/Routing/data/useMailSources', () => ({ default: vi.fn() }))
vi.mock('@components/Toaster/Toaster', () => ({
  default: { success: vi.fn(), error: vi.fn(), info: vi.fn(), warning: vi.fn() }
}))

const { capturedOnDragEnd, capturedItems } = vi.hoisted(() => ({
  capturedOnDragEnd: { current: undefined as ((event: DndKitCore.DragEndEvent) => void) | undefined },
  capturedItems: { current: [] as string[] }
}))
vi.mock('@dnd-kit/core', async importOriginal => {
  const actual = await importOriginal<typeof DndKitCore>()
  return {
    ...actual,
    DndContext: ({ children, onDragEnd }: DndKitCore.DndContextProps) => {
      capturedOnDragEnd.current = onDragEnd
      return children
    }
  }
})
vi.mock('@dnd-kit/sortable', async importOriginal => {
  const actual = await importOriginal<typeof DndKitSortable>()
  return {
    ...actual,
    SortableContext: ({ children, items }: DndKitSortable.SortableContextProps) => {
      capturedItems.current = items as string[]
      return children
    }
  }
})

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
    updateSettingsMutate.mockReset()
    vi.mocked(notify.success).mockClear()
    vi.mocked(notify.error).mockClear()
    window.location.hash = '#/routing'
    capturedOnDragEnd.current = undefined
    capturedItems.current = []
    ;(useMailSettings as Mock).mockReturnValue({ data: settings, isPending: false })
    ;(useUpdateSettings as Mock).mockReturnValue({ mutate: updateSettingsMutate, isPending: false })
    ;(useMailSources as Mock).mockReturnValue({ data: [{ value: 'woocommerce', label: 'WooCommerce' }] })
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

  it('offers the detected source plugins when a condition switches to Source plugin', async () => {
    renderWithProviders(<RoutingRulesPage />)

    await userEvent.click(screen.getByRole('combobox', { name: 'Field' }))
    await userEvent.click(await screen.findByTitle('Source plugin'))
    await userEvent.click(screen.getByRole('combobox', { name: 'Value' }))

    expect(await screen.findByTitle('WooCommerce (woocommerce)')).toBeInTheDocument()
  })

  it('adds a rule row when Add rule is clicked', async () => {
    renderWithProviders(<RoutingRulesPage />)

    expect(screen.getAllByRole('combobox', { name: 'Target connection' })).toHaveLength(1)

    await userEvent.click(screen.getByRole('button', { name: /Add rule/ }))

    expect(screen.getAllByRole('combobox', { name: 'Target connection' })).toHaveLength(2)
  })

  it('keeps Save disabled and shows no unsaved-changes notice until something changes', () => {
    renderWithProviders(<RoutingRulesPage />)

    expect(screen.getByRole('button', { name: 'Save changes' })).toBeDisabled()
    expect(screen.queryByText('You have unsaved changes')).not.toBeInTheDocument()
  })

  it('flags unsaved changes and discards them back to the saved rules', async () => {
    renderWithProviders(<RoutingRulesPage />)

    await userEvent.type(screen.getByLabelText('Value'), 's')
    expect(screen.getByText('You have unsaved changes')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Discard changes' }))

    expect(screen.getByLabelText('Value')).toHaveValue('invoice')
    expect(screen.getByRole('button', { name: 'Save changes' })).toBeDisabled()
  })

  it('confirms a successful save with a toast', async () => {
    updateSettingsMutate.mockImplementation((_payload, options) =>
      options?.onSuccess?.({ status: 'success', data: {} })
    )
    renderWithProviders(<RoutingRulesPage />)

    await userEvent.type(screen.getByLabelText('Value'), 's')
    await userEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    expect(notify.success).toHaveBeenCalledWith('Routing rules saved.')
  })

  it('reports a rejected save instead of claiming success', async () => {
    updateSettingsMutate.mockImplementation((_payload, options) =>
      options?.onSuccess?.({ status: 'error', data: {} })
    )
    renderWithProviders(<RoutingRulesPage />)

    await userEvent.type(screen.getByLabelText('Value'), 's')
    await userEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    expect(notify.error).toHaveBeenCalledWith("Couldn't save routing rules.")
    expect(notify.success).not.toHaveBeenCalled()
  })

  it('shows an empty state explaining routing when there are no rules', async () => {
    ;(useMailSettings as Mock).mockReturnValue({
      data: { ...settings, features: {} },
      isPending: false
    })
    renderWithProviders(<RoutingRulesPage />)

    expect(screen.getByText('No routing rules')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Add rule' }))
    expect(screen.getAllByRole('combobox', { name: 'Target connection' })).toHaveLength(1)
  })

  it('asks before following an in-app link while there are unsaved changes', async () => {
    renderWithProviders(
      <>
        <a href="#/logs">Logs</a>
        <RoutingRulesPage />
      </>
    )

    await userEvent.type(screen.getByLabelText('Value'), 's')
    await userEvent.click(screen.getByRole('link', { name: 'Logs' }))

    expect(await screen.findByText('Leave without saving?')).toBeInTheDocument()
    expect(window.location.hash).not.toBe('#/logs')

    await userEvent.click(screen.getByRole('button', { name: 'Leave without saving' }))
    expect(window.location.hash).toBe('#/logs')
  })

  it('posts an updated condition value after editing it', async () => {
    renderWithProviders(<RoutingRulesPage />)

    await userEvent.clear(screen.getByLabelText('Value'))
    await userEvent.type(screen.getByLabelText('Value'), 'receipt')
    await userEvent.click(screen.getByRole('button', { name: 'Save changes' }))

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
    const popconfirm = (await screen.findByText(/Matching emails will use/)).closest(
      '.ant-popover'
    ) as HTMLElement
    await userEvent.click(within(popconfirm).getByRole('button', { name: 'Delete rule' }))
    await userEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    const [payload] = updateSettingsMutate.mock.calls[0]
    expect(payload).toEqual({ features: { routing: [] } })
  })

  it('builds a new rule with one empty condition and no connection when added and saved', async () => {
    renderWithProviders(<RoutingRulesPage />)

    await userEvent.click(screen.getByRole('button', { name: /Add rule/ }))
    await userEvent.click(screen.getByRole('button', { name: 'Save changes' }))

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

  it('reorders rules on drag and includes the new order in the Save payload', async () => {
    renderWithProviders(<RoutingRulesPage />)
    await userEvent.click(screen.getByRole('button', { name: /Add rule/ }))

    act(() => {
      capturedOnDragEnd.current?.({
        active: { id: capturedItems.current[1] },
        over: { id: capturedItems.current[0] }
      } as DndKitCore.DragEndEvent)
    })
    await userEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    const [payload] = updateSettingsMutate.mock.calls[0]
    expect(payload).toEqual({
      features: {
        routing: [
          { connectionId: '', conditions: [{ field: 'recipient', operator: 'equals', value: '' }] },
          {
            connectionId: 'conn_2',
            conditions: [{ field: 'subject', operator: 'contains', value: 'invoice' }]
          }
        ]
      }
    })
  })
})
