import type * as ReactRouterDom from 'react-router-dom'
import { renderWithProviders } from '@config/test-utils'
import type * as DndKitCore from '@dnd-kit/core'
import { type MailSettings, type ProviderMeta } from '@pages/Connections/types'
import { act, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { type Mock, beforeEach, describe, expect, it, vi } from 'vitest'
import ConnectionsListPage from './ConnectionsListPage'
import useDeleteConnection from './data/useDeleteConnection'
import useMailSettings from './data/useMailSettings'
import useProviders from './data/useProviders'
import useSetDefaultConnection from './data/useSetDefaultConnection'
import useUpdateSettings from './data/useUpdateSettings'

vi.mock('./data/useMailSettings', () => ({ default: vi.fn() }))
vi.mock('./data/useProviders', () => ({ default: vi.fn() }))
vi.mock('./data/useSetDefaultConnection', () => ({ default: vi.fn() }))
vi.mock('./data/useDeleteConnection', () => ({ default: vi.fn() }))
vi.mock('./data/useUpdateSettings', () => ({ default: vi.fn() }))

const navigateMock = vi.fn()
vi.mock('react-router-dom', async importOriginal => ({
  ...(await importOriginal<typeof ReactRouterDom>()),
  useNavigate: () => navigateMock
}))

// dnd-kit needs real pointer events to drag in jsdom, so DndContext is swapped for a
// pass-through that captures onDragEnd, letting tests fire it like a real drop.
const { capturedOnDragEnd } = vi.hoisted(() => ({
  capturedOnDragEnd: { current: undefined as ((event: DndKitCore.DragEndEvent) => void) | undefined }
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

const otherSmtpMeta: ProviderMeta = {
  key: 'other_smtp',
  label: 'Other SMTP',
  kind: 'smtp',
  fields: []
}

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
  features: {}
}

describe('ConnectionsListPage', () => {
  const setDefaultMutate = vi.fn()
  const deleteMutate = vi.fn()
  const updateSettingsMutate = vi.fn()

  beforeEach(() => {
    navigateMock.mockClear()
    setDefaultMutate.mockClear()
    deleteMutate.mockClear()
    updateSettingsMutate.mockClear()
    capturedOnDragEnd.current = undefined
    ;(useMailSettings as Mock).mockReturnValue({ data: settings, isPending: false })
    ;(useProviders as Mock).mockReturnValue({ data: [otherSmtpMeta], isPending: false })
    ;(useSetDefaultConnection as Mock).mockReturnValue({ mutate: setDefaultMutate })
    ;(useDeleteConnection as Mock).mockReturnValue({ mutate: deleteMutate })
    ;(useUpdateSettings as Mock).mockReturnValue({ mutate: updateSettingsMutate })
  })

  it('renders a card per connection with a Default tag on the default one', () => {
    renderWithProviders(<ConnectionsListPage />)

    expect(screen.getByText('Primary SMTP')).toBeInTheDocument()
    expect(screen.getByText('Backup SMTP')).toBeInTheDocument()
    expect(screen.getAllByText('Default')).toHaveLength(1)
  })

  it('shows a loading spinner while settings or providers are pending', () => {
    ;(useMailSettings as Mock).mockReturnValue({ data: undefined, isPending: true })

    const { container } = renderWithProviders(<ConnectionsListPage />)

    expect(container.querySelector('.ant-spin')).toBeInTheDocument()
  })

  it('navigates to the connection detail route when Edit is clicked', async () => {
    renderWithProviders(<ConnectionsListPage />)

    const backupCard = screen.getByText('Backup SMTP').closest('.ant-card') as HTMLElement
    await userEvent.click(within(backupCard).getByRole('button', { name: /Edit/ }))

    expect(navigateMock).toHaveBeenCalledWith('/connection/conn_2')
  })

  it('opens the provider selector modal when Add connection is clicked', async () => {
    renderWithProviders(<ConnectionsListPage />)

    await userEvent.click(screen.getByRole('button', { name: 'Add connection' }))

    expect(screen.getByText('Choose a provider')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Other SMTP' })).toBeInTheDocument()
    expect(navigateMock).not.toHaveBeenCalled()
  })

  it('navigates to the new-connection route with the provider when a provider is selected', async () => {
    renderWithProviders(<ConnectionsListPage />)

    await userEvent.click(screen.getByRole('button', { name: 'Add connection' }))
    await userEvent.click(screen.getByRole('button', { name: 'Other SMTP' }))

    expect(navigateMock).toHaveBeenCalledWith('/connection/new?provider=other_smtp')
  })

  it('calls the set-default mutation with the connection id', async () => {
    renderWithProviders(<ConnectionsListPage />)

    const backupCard = screen.getByText('Backup SMTP').closest('.ant-card') as HTMLElement
    await userEvent.click(within(backupCard).getByRole('button', { name: /Set default/ }))

    expect(setDefaultMutate).toHaveBeenCalledWith('conn_2')
  })

  it('calls the delete mutation with the connection id after confirming', async () => {
    renderWithProviders(<ConnectionsListPage />)

    const backupCard = screen.getByText('Backup SMTP').closest('.ant-card') as HTMLElement
    await userEvent.click(within(backupCard).getByRole('button', { name: /Delete/ }))
    await userEvent.click(await screen.findByRole('button', { name: 'OK' }))

    expect(deleteMutate).toHaveBeenCalledWith('conn_2')
  })

  it('shows each card priority position', () => {
    renderWithProviders(<ConnectionsListPage />)

    expect(screen.getByText('Priority 1')).toBeInTheDocument()
    expect(screen.getByText('Priority 2')).toBeInTheDocument()
  })

  it('persists the reordered fallback_connection_ids when a card is dragged to a new position', () => {
    renderWithProviders(<ConnectionsListPage />)

    act(() => {
      capturedOnDragEnd.current?.({
        active: { id: 'conn_2' },
        over: { id: 'conn_1' }
      } as DndKitCore.DragEndEvent)
    })

    expect(updateSettingsMutate).toHaveBeenCalledWith({ fallback_connection_ids: ['conn_2', 'conn_1'] })
    expect(screen.getByText('Primary SMTP')).toBeInTheDocument()
    expect(screen.getByText('Backup SMTP')).toBeInTheDocument()
  })

  it('does not persist an order change when a card is dropped back on itself', () => {
    renderWithProviders(<ConnectionsListPage />)

    act(() => {
      capturedOnDragEnd.current?.({
        active: { id: 'conn_1' },
        over: { id: 'conn_1' }
      } as DndKitCore.DragEndEvent)
    })

    expect(updateSettingsMutate).not.toHaveBeenCalled()
  })

  it('shows an empty state with an add button when there are no connections', () => {
    ;(useMailSettings as Mock).mockReturnValue({
      data: { ...settings, connections: [], default_connection_id: '' },
      isPending: false
    })

    renderWithProviders(<ConnectionsListPage />)

    expect(screen.getByText(/no connections/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Add connection/i })).toBeInTheDocument()
  })
})
