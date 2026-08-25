import { renderWithProviders } from '@config/test-utils'
import { screen } from '@testing-library/react'
import { Form } from 'antd'
import { describe, expect, it } from 'vitest'
import PrivacyData from './PrivacyData'

/** Render PrivacyData inside a Form (its fields bind to a Form context) and the app providers. */
function renderPanel() {
  return renderWithProviders(
    <Form>
      <PrivacyData />
    </Form>
  )
}

describe('PrivacyData tracking copy', () => {
  it('renders the tracking toggle', () => {
    renderPanel()
    expect(screen.getByText('Enable open/click tracking')).toBeInTheDocument()
  })

  it('renders the honest, expanded privacy copy for open/click tracking', () => {
    renderPanel()

    // Off-by-default + what turning it on does.
    expect(screen.getByText(/Off by default\./)).toBeInTheDocument()
    // Depends on logging being enabled.
    expect(screen.getByText(/Requires logging to be enabled/)).toBeInTheDocument()
    // Automated-fetch honesty (Apple MPP / image proxies flagged separately).
    expect(screen.getByText(/Apple Mail Privacy Protection/)).toBeInTheDocument()
    // GDPR export/erase coverage.
    expect(screen.getByText(/personal-data export and erasure tools/)).toBeInTheDocument()
  })
})
