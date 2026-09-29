import { renderWithProviders } from '@config/test-utils'
import { type ProviderMeta } from '@pages/Connections/types'
import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { type Mock, beforeEach, describe, expect, it, vi } from 'vitest'
import ProviderSelectorModal from './ProviderSelectorModal'
import useProviders from './data/useProviders'

vi.mock('./data/useProviders', () => ({ default: vi.fn() }))

const providers: ProviderMeta[] = [
  {
    key: 'gmail',
    label: 'Gmail',
    kind: 'api',
    fields: [],
    group: 'mailbox',
    bestFor: 'Low-volume mail from your own mailbox.',
    oauth_redirect_url: 'https://site.test/bit-smtp/oauth/callback'
  },
  {
    key: 'other_smtp',
    label: 'Other SMTP',
    kind: 'smtp',
    fields: [],
    group: 'mailbox',
    bestFor: 'Any mail server.'
  },
  {
    key: 'postmark',
    label: 'Postmark',
    kind: 'api',
    fields: [],
    group: 'transactional',
    bestFor: 'Receipts and password resets.'
  },
  {
    key: 'sendgrid',
    label: 'SendGrid',
    kind: 'api',
    fields: [],
    group: 'transactional',
    bestFor: 'Any volume.'
  },
  {
    key: 'php_sendmail',
    label: 'PHP Sendmail',
    kind: 'local',
    fields: [],
    group: 'server',
    bestFor: 'Backup.'
  },
  { key: 'future', label: 'Future Mail', kind: 'api', fields: [] }
]

/** Renders an open picker with the given callbacks. */
function renderPicker(onSelect = vi.fn(), onClose = vi.fn()) {
  renderWithProviders(<ProviderSelectorModal open onClose={onClose} onSelect={onSelect} />)
  return { onSelect, onClose }
}

describe('ProviderSelectorModal', () => {
  beforeEach(() => {
    ;(useProviders as Mock).mockReturnValue({ data: providers, isPending: false })
  })

  it('groups providers under plain-language headings, in order', () => {
    renderPicker()

    const groups = screen.getAllByRole('region')
    expect(groups.map(group => group.getAttribute('aria-label'))).toEqual([
      'Use your own mailbox',
      'Email sending services',
      'Your web server',
      'Other providers'
    ])
    expect(within(groups[1]).getByRole('button', { name: 'Postmark' })).toBeInTheDocument()
    expect(within(groups[3]).getByRole('button', { name: 'Future Mail' })).toBeInTheDocument()
  })

  it('names each tile by its label and describes it with its best-for line and translated tags', () => {
    renderPicker()

    expect(screen.getByRole('button', { name: 'Gmail' })).toHaveAccessibleDescription(
      'Low-volume mail from your own mailbox. Sign in'
    )
    expect(screen.getByRole('button', { name: 'Other SMTP' })).toHaveAccessibleDescription(
      'Any mail server. Username & password'
    )
    expect(screen.getByRole('button', { name: 'PHP Sendmail' })).toHaveAccessibleDescription(
      'Backup. No setup Not recommended'
    )
    expect(screen.queryByText('smtp')).not.toBeInTheDocument()
  })

  it('shows "Not sure which to choose?" guidance until the user searches', async () => {
    renderPicker()

    expect(screen.getByText('Not sure which to choose?')).toBeInTheDocument()

    await userEvent.type(screen.getByRole('textbox', { name: 'Search providers' }), 'post')

    expect(screen.queryByText('Not sure which to choose?')).not.toBeInTheDocument()
  })

  it('filters by name and by the best-for line', async () => {
    renderPicker()
    const search = screen.getByRole('textbox', { name: 'Search providers' })

    await userEvent.type(search, 'post')
    expect(screen.getByRole('button', { name: 'Postmark' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'SendGrid' })).not.toBeInTheDocument()
    expect(screen.queryByRole('region', { name: 'Use your own mailbox' })).not.toBeInTheDocument()

    await userEvent.clear(search)
    await userEvent.type(search, 'password resets')
    expect(screen.getByRole('button', { name: 'Postmark' })).toBeInTheDocument()
  })

  it('says so when nothing matches', async () => {
    renderPicker()

    await userEvent.type(screen.getByRole('textbox', { name: 'Search providers' }), 'zzz')

    expect(screen.getByText('No providers match your search.')).toBeInTheDocument()
  })

  it('does not render modal content when closed', () => {
    renderWithProviders(<ProviderSelectorModal open={false} onClose={() => {}} onSelect={() => {}} />)

    expect(screen.queryByText('Choose a provider')).not.toBeInTheDocument()
  })

  it('shows a spinner while providers are loading', () => {
    ;(useProviders as Mock).mockReturnValue({ data: undefined, isPending: true })

    renderPicker()

    expect(document.querySelector('.ant-spin')).toBeInTheDocument()
  })

  it('calls onSelect and onClose with the provider key when selected', async () => {
    const { onSelect, onClose } = renderPicker()

    await userEvent.click(screen.getByRole('button', { name: 'Other SMTP' }))

    expect(onSelect).toHaveBeenCalledWith('other_smtp')
    expect(onClose).toHaveBeenCalled()
  })

  it('renders a decorative logo for a provider that has one', () => {
    renderPicker()

    const tile = screen.getByRole('button', { name: 'SendGrid' })
    expect(within(tile).getByTestId('provider-logo')).toHaveAttribute('aria-hidden', 'true')
  })
})
