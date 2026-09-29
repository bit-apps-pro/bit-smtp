import { type Connection, type ConnectionHealth } from '@pages/Connections/types'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import ConnectionCard from './ConnectionCard'

const connection: Connection = {
  id: 'conn_a',
  provider: 'other_smtp',
  kind: 'smtp',
  name: 'Primary SMTP',
  enabled: true,
  fromEmail: 'a@b.c',
  fromName: 'A',
  replyToEmail: '',
  settings: {},
  credentials: {}
}

type CardProps = Partial<Parameters<typeof ConnectionCard>[0]>

/** Renders ConnectionCard with sensible default props, overridable per test. */
function renderCard(props: CardProps = {}) {
  return render(
    <ConnectionCard
      connection={connection}
      isDefault={false}
      onSetDefault={() => {}}
      onToggleEnabled={() => {}}
      onEdit={() => {}}
      onDelete={() => {}}
      // eslint-disable-next-line react/jsx-props-no-spreading -- test helper merges optional overrides
      {...props}
    />
  )
}

describe('ConnectionCard', () => {
  it('renders the name, provider chip and fromEmail', () => {
    renderCard()

    expect(screen.getByText('Primary SMTP')).toBeInTheDocument()
    expect(screen.getByText('Any SMTP server')).toBeInTheDocument()
    expect(screen.getByText('a@b.c')).toBeInTheDocument()
  })

  it('renders the connection name as its own header row', () => {
    renderCard()

    expect(screen.getByRole('heading', { name: 'Primary SMTP' })).toBeInTheDocument()
  })

  it('groups connection controls in a subtitle below the name', () => {
    renderCard({ backupNumber: 2 })

    const subtitle = screen.getByRole('group', { name: 'Primary SMTP' })
    expect(subtitle).toHaveTextContent('Backup #2')
    expect(screen.getByRole('switch', { name: 'Disable this connection' })).toBeInTheDocument()
  })

  it('labels the default connection Primary instead of a backup number', () => {
    renderCard({ isDefault: true, backupNumber: 0 })

    expect(screen.getByText('Primary')).toBeInTheDocument()
    expect(screen.queryByText(/Backup #/)).not.toBeInTheDocument()
  })

  it('hides Set as primary on the primary card', () => {
    renderCard({ isDefault: true })

    expect(screen.queryByRole('button', { name: /Set as primary/ })).not.toBeInTheDocument()
  })

  it('calls onSetDefault when Set as primary is clicked', async () => {
    const onSetDefault = vi.fn()
    renderCard({ onSetDefault })

    await userEvent.click(screen.getByRole('button', { name: /Set as primary/ }))

    expect(onSetDefault).toHaveBeenCalledTimes(1)
  })

  it('offers no drag handle on the locked primary card', () => {
    renderCard({ isDefault: true, dragHandleListeners: {} })

    expect(screen.queryByRole('button', { name: /Drag to reorder/ })).not.toBeInTheDocument()
  })

  it('offers a named drag handle on a backup card', () => {
    renderCard({ dragHandleListeners: {} })

    expect(screen.getByRole('button', { name: 'Drag to reorder' })).toBeInTheDocument()
  })

  it('calls onEdit when Edit is clicked', async () => {
    const onEdit = vi.fn()
    renderCard({ onEdit })

    await userEvent.click(screen.getByRole('button', { name: /Edit/ }))

    expect(onEdit).toHaveBeenCalledTimes(1)
  })

  it('toggles enabled off with the flipped value when the switch is clicked', async () => {
    const onToggleEnabled = vi.fn()
    renderCard({ onToggleEnabled })

    await userEvent.click(screen.getByRole('switch', { name: 'Disable this connection' }))

    expect(onToggleEnabled.mock.calls[0][0]).toBe(false)
  })

  it('toggles enabled on with the flipped value for a disabled connection', async () => {
    const onToggleEnabled = vi.fn()
    renderCard({ connection: { ...connection, enabled: false }, onToggleEnabled })

    expect(screen.getByText('Disabled')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('switch', { name: 'Enable this connection' }))

    expect(onToggleEnabled.mock.calls[0][0]).toBe(true)
  })

  it('does not allow a disabled connection to be set as primary, and says why', async () => {
    renderCard({ connection: { ...connection, enabled: false } })

    const button = screen.getByRole('button', { name: /Set as primary/ })
    expect(button).toBeDisabled()
    await userEvent.hover(button.parentElement as HTMLElement)
    expect(await screen.findByText('Turn this connection on first')).toBeInTheDocument()
  })

  it('renders the health badge only when health is provided', () => {
    const health: ConnectionHealth = {
      status: 'unhealthy',
      circuit: 'open',
      consecutive_failures: 3,
      last_ok_at: null,
      last_error: 'SMTP connect() failed',
      last_probe_at: '2026-08-24T10:00:00Z',
      oauth_expires_at: null
    }

    const { rerender } = renderCard()
    expect(screen.queryByText('Unhealthy')).not.toBeInTheDocument()

    rerender(
      <ConnectionCard
        connection={connection}
        isDefault={false}
        health={health}
        onSetDefault={() => {}}
        onToggleEnabled={() => {}}
        onEdit={() => {}}
        onDelete={() => {}}
      />
    )
    expect(screen.getByText('Unhealthy')).toBeInTheDocument()
  })

  it('calls onDelete only after the Popconfirm is confirmed with a verb button', async () => {
    const onDelete = vi.fn()
    renderCard({ onDelete })

    await userEvent.click(screen.getByRole('button', { name: /Delete/ }))
    expect(onDelete).not.toHaveBeenCalled()
    expect(await screen.findByText('Delete "Primary SMTP"?')).toBeInTheDocument()

    await userEvent.click(await screen.findByRole('button', { name: 'Delete connection' }))

    expect(onDelete).toHaveBeenCalledTimes(1)
  })

  it('warns in the delete confirmation that routing rules using it are deleted too', async () => {
    renderCard({ routingRuleCount: 2, isDefault: true })

    await userEvent.click(screen.getByRole('button', { name: /Delete/ }))

    expect(
      await screen.findByText(
        'The 2 routing rules that use it are deleted too. Another enabled connection becomes the primary.'
      )
    ).toBeInTheDocument()
  })
})
