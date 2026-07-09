import { type Connection } from '@pages/Connections/types'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import DefaultConnectionSelect from './DefaultConnectionSelect'

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

describe('DefaultConnectionSelect', () => {
  it('renders connection names as options', async () => {
    render(<DefaultConnectionSelect connections={connections} defaultId="conn_a" onChange={() => {}} />)

    await userEvent.click(screen.getByRole('combobox'))

    expect(await screen.findByText('Backup')).toBeInTheDocument()
  })

  it('calls onChange with the newly selected connection id', async () => {
    const onChange = vi.fn()
    render(<DefaultConnectionSelect connections={connections} defaultId="conn_a" onChange={onChange} />)

    await userEvent.click(screen.getByRole('combobox'))
    await userEvent.click(await screen.findByText('Backup'))

    expect(onChange).toHaveBeenCalledWith('conn_b')
  })
})
