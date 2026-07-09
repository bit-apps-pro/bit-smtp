import { type Connection } from '@pages/Connections/types'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import ConnectionList from './ConnectionList'

const connections: Connection[] = [
  {
    id: 'conn_a',
    provider: 'other_smtp',
    kind: 'smtp',
    name: 'Primary',
    enabled: true,
    fromEmail: '',
    fromName: '',
    replyToEmail: '',
    settings: {},
    credentials: {}
  },
  {
    id: 'conn_b',
    provider: 'other_smtp',
    kind: 'smtp',
    name: 'Backup',
    enabled: true,
    fromEmail: '',
    fromName: '',
    replyToEmail: '',
    settings: {},
    credentials: {}
  }
]

describe('ConnectionList', () => {
  it('renders a card per connection with its name and provider', () => {
    render(
      <ConnectionList
        connections={connections}
        defaultId="conn_a"
        selectedId={null}
        onSelect={() => {}}
        onDelete={() => {}}
      />
    )

    expect(screen.getByText('Primary')).toBeInTheDocument()
    expect(screen.getByText('Backup')).toBeInTheDocument()
    expect(screen.getAllByText('other_smtp')).toHaveLength(2)
  })

  it('shows the Default tag only on the defaultId connection', () => {
    render(
      <ConnectionList
        connections={connections}
        defaultId="conn_b"
        selectedId={null}
        onSelect={() => {}}
        onDelete={() => {}}
      />
    )

    expect(screen.getAllByText('Default')).toHaveLength(1)
  })

  it('calls onSelect with the connection id when a connection is chosen', async () => {
    const onSelect = vi.fn()
    render(
      <ConnectionList
        connections={connections}
        defaultId="conn_a"
        selectedId={null}
        onSelect={onSelect}
        onDelete={() => {}}
      />
    )

    await userEvent.click(screen.getByRole('button', { name: 'Backup' }))

    expect(onSelect).toHaveBeenCalledWith('conn_b')
  })

  it('calls onDelete with the connection id when delete is clicked', async () => {
    const onDelete = vi.fn()
    render(
      <ConnectionList
        connections={connections}
        defaultId="conn_a"
        selectedId={null}
        onSelect={() => {}}
        onDelete={onDelete}
      />
    )

    await userEvent.click(screen.getByRole('button', { name: 'Delete Backup' }))

    expect(onDelete).toHaveBeenCalledWith('conn_b')
  })
})
