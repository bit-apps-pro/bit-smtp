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

  it('shows a Default tag only when isDefault is true', () => {
    const { rerender } = renderCard({ isDefault: false })
    expect(screen.queryByText('Default')).not.toBeInTheDocument()

    rerender(
      <ConnectionCard
        connection={connection}
        isDefault
        onSetDefault={() => {}}
        onToggleEnabled={() => {}}
        onEdit={() => {}}
        onDelete={() => {}}
      />
    )
    expect(screen.getByText('Default')).toBeInTheDocument()
  })

  it('calls onSetDefault when Set default is clicked', async () => {
    const onSetDefault = vi.fn()
    renderCard({ onSetDefault })

    await userEvent.click(screen.getByRole('button', { name: /Set default/ }))

    expect(onSetDefault).toHaveBeenCalledTimes(1)
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

    // antd Switch calls onChange(checked, event); the flipped `checked` is the load-bearing arg.
    expect(onToggleEnabled.mock.calls[0][0]).toBe(false)
  })

  it('toggles enabled on with the flipped value for a disabled connection', async () => {
    const onToggleEnabled = vi.fn()
    renderCard({ connection: { ...connection, enabled: false }, onToggleEnabled })

    expect(screen.getByText('Disabled')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('switch', { name: 'Enable this connection' }))

    expect(onToggleEnabled.mock.calls[0][0]).toBe(true)
  })

  it('does not allow a disabled connection to be set as default', () => {
    renderCard({ connection: { ...connection, enabled: false } })

    expect(screen.getByRole('button', { name: /Set default/ })).toBeDisabled()
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

  it('calls onDelete only after the Popconfirm is confirmed', async () => {
    const onDelete = vi.fn()
    renderCard({ onDelete })

    await userEvent.click(screen.getByRole('button', { name: /Delete/ }))
    expect(onDelete).not.toHaveBeenCalled()

    await userEvent.click(await screen.findByRole('button', { name: 'OK' }))

    expect(onDelete).toHaveBeenCalledTimes(1)
  })
})
