import { HEALTH_STATUSES } from '@common/glossary'
import { type ConnectionHealth } from '@pages/Connections/types'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import HealthBadge from './HealthBadge'

const base: ConnectionHealth = {
  status: 'healthy',
  circuit: 'closed',
  consecutive_failures: 0,
  last_ok_at: '2026-08-24T09:00:00Z',
  last_error: null,
  last_probe_at: '2026-08-24T10:00:00Z',
  oauth_expires_at: null
}

describe('HealthBadge', () => {
  it.each([
    ['healthy', 'Healthy', 'ant-tag-success'],
    ['degraded', 'Degraded', 'ant-tag-warning'],
    ['unhealthy', 'Unhealthy', 'ant-tag-error'],
    ['unknown', 'Not checked yet', 'ant-tag-default']
  ] as const)('renders %s as the "%s" tag with the %s color', (status, label, colorClass) => {
    render(<HealthBadge health={{ ...base, status }} />)

    const tag = screen.getByText(label)
    expect(tag).toBeInTheDocument()
    expect(tag).toHaveClass(colorClass)
  })

  it('opens a click popover with the status meaning and the last error, success and check', async () => {
    render(
      <HealthBadge health={{ ...base, status: 'unhealthy', last_error: 'SMTP connect() failed' }} />
    )

    await userEvent.click(screen.getByRole('button', { name: /health details/ }))

    expect(await screen.findByText(/SMTP connect\(\) failed/)).toBeInTheDocument()
    expect(screen.getByText(HEALTH_STATUSES.unhealthy.hint)).toBeInTheDocument()
    expect(screen.getByText(/Last success:/)).toBeInTheDocument()
    expect(screen.getByText(/Last checked:/)).toBeInTheDocument()
  })

  it('notes an expired OAuth token in the tooltip', async () => {
    const expiresAt = Math.floor(Date.now() / 1000) - 60

    render(<HealthBadge health={{ ...base, status: 'unhealthy', oauth_expires_at: expiresAt }} />)
    await userEvent.click(screen.getByRole('button', { name: /health details/ }))

    expect(await screen.findByText(/OAuth token has expired/)).toBeInTheDocument()
  })

  it('warns when an OAuth token is expiring soon', async () => {
    const expiresAt = Math.floor(Date.now() / 1000) + 60 * 60

    render(<HealthBadge health={{ ...base, oauth_expires_at: expiresAt }} />)
    await userEvent.click(screen.getByRole('button', { name: /health details/ }))

    expect(await screen.findByText(/OAuth token expires soon/)).toBeInTheDocument()
  })

  it('shows no OAuth note while the token is safely valid', async () => {
    const expiresAt = Math.floor(Date.now() / 1000) + 30 * 24 * 60 * 60

    render(<HealthBadge health={{ ...base, oauth_expires_at: expiresAt }} />)
    await userEvent.click(screen.getByRole('button', { name: /health details/ }))

    await screen.findByText(/Last success:/)
    expect(screen.queryByText(/OAuth token/)).not.toBeInTheDocument()
  })

  it('names the help trigger after the connection so each card is distinguishable', () => {
    render(<HealthBadge health={base} connectionName="Brevo" />)

    expect(screen.getByRole('button', { name: 'Healthy, health details for Brevo' })).toBeInTheDocument()
  })

  it('opens the popover from the status text too, through one focusable control', async () => {
    render(<HealthBadge health={{ ...base, status: 'degraded' }} />)

    expect(screen.getAllByRole('button')).toHaveLength(1)
    await userEvent.click(screen.getByText('Degraded'))

    expect(await screen.findByText(HEALTH_STATUSES.degraded.hint)).toBeInTheDocument()
  })
})
