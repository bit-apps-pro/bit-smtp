import { renderWithProviders } from '@config/test-utils'
import { type ProviderMeta } from '@pages/Connections/types'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { type Mock, beforeEach, describe, expect, it, vi } from 'vitest'
import ProviderSelectorModal from './ProviderSelectorModal'
import useProviders from './data/useProviders'

vi.mock('./data/useProviders', () => ({ default: vi.fn() }))

const otherSmtpMeta: ProviderMeta = {
  key: 'other_smtp',
  label: 'Other SMTP',
  kind: 'smtp',
  fields: []
}

describe('ProviderSelectorModal', () => {
  beforeEach(() => {
    ;(useProviders as Mock).mockReturnValue({ data: [otherSmtpMeta], isPending: false })
  })

  it('renders the other_smtp provider when open', () => {
    renderWithProviders(<ProviderSelectorModal open onClose={() => {}} onSelect={() => {}} />)

    expect(screen.getByText('Choose a provider')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Other SMTP' })).toBeInTheDocument()
    expect(screen.getByText('smtp')).toBeInTheDocument()
  })

  it('does not render modal content when closed', () => {
    renderWithProviders(<ProviderSelectorModal open={false} onClose={() => {}} onSelect={() => {}} />)

    expect(screen.queryByText('Choose a provider')).not.toBeInTheDocument()
  })

  it('shows a spinner while providers are loading', () => {
    ;(useProviders as Mock).mockReturnValue({ data: undefined, isPending: true })

    renderWithProviders(<ProviderSelectorModal open onClose={() => {}} onSelect={() => {}} />)

    // antd Modal renders in a portal on document.body, not inside the render container.
    expect(document.querySelector('.ant-spin')).toBeInTheDocument()
  })

  it('calls onSelect and onClose with the provider key when selected', async () => {
    const onSelect = vi.fn()
    const onClose = vi.fn()
    renderWithProviders(<ProviderSelectorModal open onClose={onClose} onSelect={onSelect} />)

    await userEvent.click(screen.getByRole('button', { name: 'Other SMTP' }))

    expect(onSelect).toHaveBeenCalledWith('other_smtp')
    expect(onClose).toHaveBeenCalled()
  })
})
