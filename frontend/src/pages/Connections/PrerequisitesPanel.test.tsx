import { renderWithProviders } from '@config/test-utils'
import { type ProviderMeta } from '@pages/Connections/types'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import PrerequisitesPanel from './PrerequisitesPanel'

const STORAGE_KEY = 'bit_smtp_prerequisites_open_postmark'

const postmark: ProviderMeta = {
  key: 'postmark',
  label: 'Postmark',
  kind: 'api',
  fields: [],
  prerequisites: [
    {
      id: 'verify_domain',
      title: 'Verify your sending domain',
      body: 'Add the DNS records Postmark shows you.',
      docUrl: 'https://postmarkapp.com/support/article/1046-how-do-i-verify-a-domain'
    },
    { id: 'server_token', title: 'Copy the Server API token', body: 'Find it under Servers.' }
  ]
}

describe('PrerequisitesPanel', () => {
  beforeEach(() => localStorage.clear())
  afterEach(() => vi.restoreAllMocks())

  it('renders nothing when the provider has no prerequisites', () => {
    const { container } = renderWithProviders(
      <PrerequisitesPanel provider={{ ...postmark, prerequisites: [] }} />
    )

    expect(container).toBeEmptyDOMElement()
  })

  it('is open by default and lists every step with its doc link', () => {
    renderWithProviders(<PrerequisitesPanel provider={postmark} />)

    expect(screen.getByRole('button', { name: /Before you start/ })).toHaveAttribute(
      'aria-expanded',
      'true'
    )
    expect(screen.getByText('2 steps')).toBeInTheDocument()
    expect(screen.getAllByRole('listitem')).toHaveLength(2)
    expect(screen.getByText('Verify your sending domain')).toBeInTheDocument()

    const link = screen.getByRole('link', { name: /Verify your sending domain/ })
    expect(link).toHaveAttribute('href', postmark.prerequisites?.[0].docUrl)
    expect(link).toHaveAttribute('target', '_blank')
    expect(link).toHaveAttribute('rel', 'noopener noreferrer')
    expect(screen.getAllByRole('link')).toHaveLength(1)
  })

  it('uses the singular step label for a single prerequisite', () => {
    renderWithProviders(
      <PrerequisitesPanel
        provider={{ ...postmark, prerequisites: postmark.prerequisites?.slice(0, 1) }}
      />
    )

    expect(screen.getByText('1 step')).toBeInTheDocument()
  })

  it('remembers a collapsed panel per provider', async () => {
    renderWithProviders(<PrerequisitesPanel provider={postmark} />)

    await userEvent.click(screen.getByRole('button', { name: /Before you start/ }))

    expect(localStorage.getItem(STORAGE_KEY)).toBe('false')
    expect(screen.getByRole('button', { name: /Before you start/ })).toHaveAttribute(
      'aria-expanded',
      'false'
    )
  })

  it('starts collapsed when it was collapsed before', () => {
    localStorage.setItem(STORAGE_KEY, 'false')

    renderWithProviders(<PrerequisitesPanel provider={postmark} />)

    expect(screen.getByRole('button', { name: /Before you start/ })).toHaveAttribute(
      'aria-expanded',
      'false'
    )
  })

  it('still works when storage is unavailable', async () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked')
    })
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked')
    })

    renderWithProviders(<PrerequisitesPanel provider={postmark} />)
    await userEvent.click(screen.getByRole('button', { name: /Before you start/ }))

    expect(screen.getByRole('button', { name: /Before you start/ })).toHaveAttribute(
      'aria-expanded',
      'false'
    )
  })
})
