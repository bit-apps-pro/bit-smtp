import type * as ReactRouterDom from 'react-router-dom'
import notify from '@components/Toaster/Toaster'
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
import useToggleConnectionEnabled from './data/useToggleConnectionEnabled'
import useUpdateSettings from './data/useUpdateSettings'

vi.mock('./data/useMailSettings', () => ({ default: vi.fn() }))
vi.mock('./data/useProviders', () => ({ default: vi.fn() }))
vi.mock('./data/useSetDefaultConnection', () => ({ default: vi.fn() }))
vi.mock('./data/useToggleConnectionEnabled', () => ({ default: vi.fn() }))
vi.mock('./data/useDeleteConnection', () => ({ default: vi.fn() }))
vi.mock('./data/useUpdateSettings', () => ({ default: vi.fn() }))
vi.mock('@components/Toaster/Toaster', () => ({
  default: { success: vi.fn(), error: vi.fn(), info: vi.fn(), warning: vi.fn() }
}))

const navigateMock = vi.fn()
vi.mock('react-router-dom', async importOriginal => ({
  ...(await importOriginal<typeof ReactRouterDom>()),
  useNavigate: () => navigateMock
}))

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

const thirdConnection = {
  id: 'conn_3',
  provider: 'other_smtp',
  kind: 'smtp',
  name: 'Third SMTP',
  enabled: true,
  fromEmail: 'g@h.i',
  fromName: 'G',
  replyToEmail: '',
  settings: {},
  credentials: {}
} as const

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
  const toggleEnabledMutate = vi.fn()
  const deleteMutate = vi.fn()
  const updateSettingsMutate = vi.fn()

  beforeEach(() => {
    navigateMock.mockClear()
    setDefaultMutate.mockClear()
    toggleEnabledMutate.mockClear()
    deleteMutate.mockClear()
    updateSettingsMutate.mockReset()
    vi.mocked(notify.success).mockClear()
    vi.mocked(notify.error).mockClear()
    capturedOnDragEnd.current = undefined
    ;(useMailSettings as Mock).mockReturnValue({ data: settings, isPending: false })
    ;(useProviders as Mock).mockReturnValue({ data: [otherSmtpMeta], isPending: false })
    ;(useSetDefaultConnection as Mock).mockReturnValue({ mutate: setDefaultMutate })
    ;(useToggleConnectionEnabled as Mock).mockReturnValue({
      mutate: toggleEnabledMutate,
      isPending: false,
      variables: undefined
    })
    ;(useDeleteConnection as Mock).mockReturnValue({ mutate: deleteMutate })
    ;(useUpdateSettings as Mock).mockReturnValue({ mutate: updateSettingsMutate })
  })

  it('renders a card per connection with a Primary tag on the default one', () => {
    renderWithProviders(<ConnectionsListPage />)

    expect(screen.getByText('Primary SMTP')).toBeInTheDocument()
    expect(screen.getByText('Backup SMTP')).toBeInTheDocument()
    expect(screen.getAllByText('Primary')).toHaveLength(1)
  })

  it('explains how the primary and backups fail over', () => {
    renderWithProviders(<ConnectionsListPage />)

    expect(screen.getByText(/If it fails, Bit SMTP tries each backup in order/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Check connection health' })).toBeInTheDocument()
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
    await userEvent.click(within(backupCard).getByRole('button', { name: /Set as primary/ }))

    expect(setDefaultMutate).toHaveBeenCalledWith('conn_2')
  })

  it('toggles a connection enabled off with the flipped value from its switch', async () => {
    renderWithProviders(<ConnectionsListPage />)

    const backupCard = screen.getByText('Backup SMTP').closest('.ant-card') as HTMLElement
    await userEvent.click(within(backupCard).getByRole('switch', { name: 'Disable this connection' }))

    expect(toggleEnabledMutate).toHaveBeenCalledWith({ id: 'conn_2', enabled: false })
  })

  it('calls the delete mutation with the connection id after confirming', async () => {
    renderWithProviders(<ConnectionsListPage />)

    const backupCard = screen.getByText('Backup SMTP').closest('.ant-card') as HTMLElement
    await userEvent.click(within(backupCard).getByRole('button', { name: /Delete/ }))
    await userEvent.click(await screen.findByRole('button', { name: 'Delete connection' }))

    expect(deleteMutate).toHaveBeenCalledWith('conn_2')
  })

  it('numbers every card after the primary as a backup', () => {
    renderWithProviders(<ConnectionsListPage />)

    expect(screen.getByText('Backup #1')).toBeInTheDocument()
    expect(screen.queryByText('Backup #2')).not.toBeInTheDocument()
  })

  it('persists the reordered backups and confirms with a toast', () => {
    ;(useMailSettings as Mock).mockReturnValue({
      data: { ...settings, connections: [...settings.connections, thirdConnection] },
      isPending: false
    })
    updateSettingsMutate.mockImplementation((_payload, options) =>
      options?.onSuccess?.({ status: 'success', data: {} })
    )
    renderWithProviders(<ConnectionsListPage />)

    act(() => {
      capturedOnDragEnd.current?.({
        active: { id: 'conn_3' },
        over: { id: 'conn_2' }
      } as DndKitCore.DragEndEvent)
    })

    expect(updateSettingsMutate).toHaveBeenCalledWith(
      { fallback_connection_ids: ['conn_1', 'conn_3', 'conn_2'] },
      expect.any(Object)
    )
    expect(notify.success).toHaveBeenCalledWith('Failover order saved.')
  })

  it('keeps the primary first when a backup is dropped onto it', () => {
    ;(useMailSettings as Mock).mockReturnValue({
      data: { ...settings, connections: [...settings.connections, thirdConnection] },
      isPending: false
    })
    renderWithProviders(<ConnectionsListPage />)

    act(() => {
      capturedOnDragEnd.current?.({
        active: { id: 'conn_3' },
        over: { id: 'conn_1' }
      } as DndKitCore.DragEndEvent)
    })

    expect(updateSettingsMutate).toHaveBeenCalledWith(
      { fallback_connection_ids: ['conn_1', 'conn_3', 'conn_2'] },
      expect.any(Object)
    )
  })

  it('does not move the primary even if a drag of it is reported', () => {
    renderWithProviders(<ConnectionsListPage />)

    act(() => {
      capturedOnDragEnd.current?.({
        active: { id: 'conn_1' },
        over: { id: 'conn_2' }
      } as DndKitCore.DragEndEvent)
    })

    expect(updateSettingsMutate).not.toHaveBeenCalled()
  })

  it('reverts the order and reports an error when saving it fails', () => {
    ;(useMailSettings as Mock).mockReturnValue({
      data: { ...settings, connections: [...settings.connections, thirdConnection] },
      isPending: false
    })
    updateSettingsMutate.mockImplementation((_payload, options) =>
      options?.onSuccess?.({ status: 'error', data: {} })
    )
    renderWithProviders(<ConnectionsListPage />)

    act(() => {
      capturedOnDragEnd.current?.({
        active: { id: 'conn_3' },
        over: { id: 'conn_2' }
      } as DndKitCore.DragEndEvent)
    })

    expect(notify.error).toHaveBeenCalledWith("Couldn't save the failover order.")
    const names = screen.getAllByRole('heading', { level: 5 }).map(heading => heading.textContent)
    expect(names).toEqual(['Primary SMTP', 'Backup SMTP', 'Third SMTP'])
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
