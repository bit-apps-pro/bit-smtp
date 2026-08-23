import { render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import StatusStrip from './StatusStrip'

describe('StatusStrip', () => {
  it('renders all five chips with their labels and values', () => {
    render(
      <StatusStrip
        loggingEnabled
        retentionDays={30}
        timeoutSeconds={45}
        notificationsEnabled={false}
        healthEnabled
      />
    )

    const strip = screen.getByRole('group', { name: /settings status/i })
    expect(within(strip).getByText('Logging')).toBeInTheDocument()
    expect(within(strip).getByText('Retention')).toBeInTheDocument()
    expect(within(strip).getByText('Timeout')).toBeInTheDocument()
    expect(within(strip).getByText('Notifications')).toBeInTheDocument()
    expect(within(strip).getByText('Health')).toBeInTheDocument()
    expect(within(strip).getByText('30d')).toBeInTheDocument()
    expect(within(strip).getByText('45s')).toBeInTheDocument()
  })

  it('reflects the on/off tone independently per chip', () => {
    render(
      <StatusStrip
        loggingEnabled
        retentionDays={30}
        timeoutSeconds={45}
        notificationsEnabled={false}
        healthEnabled
      />
    )

    // Logging + Health on, Notifications off.
    const strip = screen.getByRole('group', { name: /settings status/i })
    expect(within(strip).getAllByText('On')).toHaveLength(2)
    expect(within(strip).getByText('Off')).toBeInTheDocument()
  })

  it('flips a chip between on and off as its prop changes', () => {
    const { rerender } = render(
      <StatusStrip
        loggingEnabled
        retentionDays={30}
        timeoutSeconds={45}
        notificationsEnabled={false}
        healthEnabled
      />
    )

    rerender(
      <StatusStrip
        loggingEnabled
        retentionDays={30}
        timeoutSeconds={45}
        notificationsEnabled={false}
        healthEnabled={false}
      />
    )

    const strip = screen.getByRole('group', { name: /settings status/i })
    expect(within(strip).getAllByText('Off')).toHaveLength(2) // Notifications + Health
    expect(within(strip).getAllByText('On')).toHaveLength(1) // Logging only
  })

  it('renders a status dot only on on/off chips, never on the value-only chips', () => {
    const { container } = render(
      <StatusStrip
        loggingEnabled
        retentionDays={30}
        timeoutSeconds={45}
        notificationsEnabled={false}
        healthEnabled
      />
    )

    // Logging, Notifications, Health each render one dot; Retention/Timeout are value-only chips.
    expect(container.querySelectorAll('[aria-hidden="true"]')).toHaveLength(3)
  })
})
