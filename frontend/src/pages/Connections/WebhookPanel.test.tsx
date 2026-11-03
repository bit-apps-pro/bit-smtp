import { type Connection, type ProviderMeta } from '@pages/Connections/types'
import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import WebhookPanel from './WebhookPanel'

const provider = { key: 'mailjet', label: 'Mailjet' } as ProviderMeta

/** Renders the panel for a connection whose automatic webhook set-up failed with `reason`. */
function renderFailed(reason: string) {
  const connection = {
    id: 'conn_1',
    provider: 'mailjet',
    settings: {},
    webhook_provisioning: { status: 'failed', reason, updated_at: 1 }
  } as unknown as Connection
  render(<WebhookPanel connection={connection} provider={provider} trackingFields={[]} />)
}

describe('WebhookPanel', () => {
  it('shows the stored failure reason as plain text with a hint that fits any cause', () => {
    renderFailed('Mailjet already sends "sent" events (Account settings > Event notifications).')

    expect(
      screen.getByText('Mailjet already sends "sent" events (Account settings > Event notifications).')
    ).toBeInTheDocument()
    expect(screen.getByText(/Fix the problem above/)).toBeInTheDocument()
    expect(screen.queryByText(/Check your API key's permissions/)).not.toBeInTheDocument()
  })
})
