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

describe('ConnectionCard', () => {
  it('renders the name, provider chip and fromEmail', () => {
    render(
      <ConnectionCard
        connection={connection}
        isDefault={false}
        onSetDefault={() => {}}
        onEdit={() => {}}
        onDelete={() => {}}
      />
    )

    expect(screen.getByText('Primary SMTP')).toBeInTheDocument()
    expect(screen.getByText('Any SMTP server')).toBeInTheDocument()
    expect(screen.getByText('a@b.c')).toBeInTheDocument()
  })

  it('shows a Default tag only when isDefault is true', () => {
    const { rerender } = render(
      <ConnectionCard
        connection={connection}
        isDefault={false}
        onSetDefault={() => {}}
        onEdit={() => {}}
        onDelete={() => {}}
      />
    )
    expect(screen.queryByText('Default')).not.toBeInTheDocument()

    rerender(
      <ConnectionCard
        connection={connection}
        isDefault
        onSetDefault={() => {}}
        onEdit={() => {}}
        onDelete={() => {}}
      />
    )
    expect(screen.getByText('Default')).toBeInTheDocument()
  })

  it('calls onSetDefault when Set default is clicked', async () => {
    const onSetDefault = vi.fn()
    render(
      <ConnectionCard
        connection={connection}
        isDefault={false}
        onSetDefault={onSetDefault}
        onEdit={() => {}}
        onDelete={() => {}}
      />
    )

    await userEvent.click(screen.getByRole('button', { name: /Set default/ }))

    expect(onSetDefault).toHaveBeenCalledTimes(1)
  })

  it('calls onEdit when Edit is clicked', async () => {
    const onEdit = vi.fn()
    render(
      <ConnectionCard
        connection={connection}
        isDefault={false}
        onSetDefault={() => {}}
        onEdit={onEdit}
        onDelete={() => {}}
      />
    )

    await userEvent.click(screen.getByRole('button', { name: /Edit/ }))

    expect(onEdit).toHaveBeenCalledTimes(1)
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

    const { rerender } = render(
      <ConnectionCard
        connection={connection}
        isDefault={false}
        onSetDefault={() => {}}
        onEdit={() => {}}
        onDelete={() => {}}
      />
    )
    expect(screen.queryByText('Unhealthy')).not.toBeInTheDocument()

    rerender(
      <ConnectionCard
        connection={connection}
        isDefault={false}
        health={health}
        onSetDefault={() => {}}
        onEdit={() => {}}
        onDelete={() => {}}
      />
    )
    expect(screen.getByText('Unhealthy')).toBeInTheDocument()
  })

  it('calls onDelete only after the Popconfirm is confirmed', async () => {
    const onDelete = vi.fn()
    render(
      <ConnectionCard
        connection={connection}
        isDefault={false}
        onSetDefault={() => {}}
        onEdit={() => {}}
        onDelete={onDelete}
      />
    )

    await userEvent.click(screen.getByRole('button', { name: /Delete/ }))
    expect(onDelete).not.toHaveBeenCalled()

    await userEvent.click(await screen.findByRole('button', { name: 'OK' }))

    expect(onDelete).toHaveBeenCalledTimes(1)
  })
})
