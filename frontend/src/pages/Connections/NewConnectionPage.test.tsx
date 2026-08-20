import type * as ReactRouterDom from 'react-router-dom'
import { renderWithProviders } from '@config/test-utils'
import { type ProviderMeta } from '@pages/Connections/types'
import { screen } from '@testing-library/react'
import { type Mock, beforeEach, describe, expect, it, vi } from 'vitest'
import NewConnectionPage from './NewConnectionPage'
import useProviders from './data/useProviders'

vi.mock('./data/useProviders', () => ({ default: vi.fn() }))

let searchParamsString = ''
const navigateMock = vi.fn()
vi.mock('react-router-dom', async importOriginal => ({
  ...(await importOriginal<typeof ReactRouterDom>()),
  useNavigate: () => navigateMock,
  useSearchParams: () => [new URLSearchParams(searchParamsString), vi.fn()]
}))

const otherSmtpMeta: ProviderMeta = {
  key: 'other_smtp',
  label: 'Other SMTP',
  kind: 'smtp',
  fields: [
    {
      key: 'host',
      label: 'SMTP Host',
      type: 'text',
      required: true,
      secret: false,
      placeholder: 'smtp.example.com',
      default: '',
      options: [],
      dependsOn: null
    }
  ]
}

describe('NewConnectionPage', () => {
  beforeEach(() => {
    navigateMock.mockClear()
    ;(useProviders as Mock).mockReturnValue({ data: [otherSmtpMeta], isPending: false })
  })

  it('renders a blank editor for the requested provider', () => {
    searchParamsString = 'provider=other_smtp'
    renderWithProviders(<NewConnectionPage />)

    expect(screen.getByLabelText('Name')).toHaveValue('')
    expect(screen.getByLabelText('SMTP Host')).toBeInTheDocument()
  })

  it('shows a loading spinner while providers are pending', () => {
    searchParamsString = 'provider=other_smtp'
    ;(useProviders as Mock).mockReturnValue({ data: undefined, isPending: true })

    const { container } = renderWithProviders(<NewConnectionPage />)

    expect(container.querySelector('.ant-spin')).toBeInTheDocument()
  })

  it('shows a message with a back link when no provider is requested', () => {
    searchParamsString = ''
    renderWithProviders(<NewConnectionPage />)

    expect(screen.getByText('Select a provider to create a connection')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Back to connections' })).toBeInTheDocument()
  })

  it('shows a message with a back link when the provider is unknown', () => {
    searchParamsString = 'provider=does-not-exist'
    renderWithProviders(<NewConnectionPage />)

    expect(screen.getByText('Select a provider to create a connection')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Back to connections' })).toBeInTheDocument()
  })
})
