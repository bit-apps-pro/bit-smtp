import { type ProviderMeta } from '@pages/Connections/types'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import ProviderPicker from './ProviderPicker'

const providers: ProviderMeta[] = [
  { key: 'other_smtp', label: 'Other SMTP', kind: 'smtp', fields: [] },
  { key: 'gmail', label: 'Gmail', kind: 'smtp', fields: [] }
]

describe('ProviderPicker', () => {
  it('renders the providers as selectable options', async () => {
    render(<ProviderPicker providers={providers} value="other_smtp" onChange={() => {}} />)

    await userEvent.click(screen.getByRole('combobox'))

    expect(await screen.findByText('Gmail')).toBeInTheDocument()
  })

  it('calls onChange with the selected provider key', async () => {
    const onChange = vi.fn()
    render(<ProviderPicker providers={providers} value="other_smtp" onChange={onChange} />)

    await userEvent.click(screen.getByRole('combobox'))
    await userEvent.click(await screen.findByText('Gmail'))

    expect(onChange).toHaveBeenCalledWith('gmail')
  })
})
