import { type PreferencesFormValues } from '@pages/Settings/types'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Form } from 'antd'
import { describe, expect, it, vi } from 'vitest'
import Health from './Health'

const baseValues: PreferencesFormValues = {
  logging_enabled: true,
  log_retention_days: 30,
  log_store_body: 'full',
  send_timeout_seconds: 30,
  retry_enabled: false,
  retry_max_attempts: 3,
  retry_backoff: 'exponential',
  retry_on_classes: ['transient', 'rate_limited'],
  health_check_enabled: true,
  health_check_interval: 'daily',
  notify_events: ['connection_unhealthy'],
  uninstall_purge: true,
  tracking_enabled: false
}

const WARNING = 'You won’t be alerted about connection problems'

interface HarnessProps {
  initialValues?: PreferencesFormValues
  alertsReady?: boolean
  healthAlertsSelected?: boolean
  onOpenNotifications?: () => void
}

/** Mounts Health inside a real Form with the given alert readiness. */
function Harness({
  initialValues = baseValues,
  alertsReady = true,
  healthAlertsSelected = true,
  onOpenNotifications = vi.fn()
}: HarnessProps) {
  const [form] = Form.useForm<PreferencesFormValues>()

  return (
    <Form form={form} layout="vertical" initialValues={initialValues}>
      <Health
        form={form}
        alertsReady={alertsReady}
        healthAlertsSelected={healthAlertsSelected}
        onOpenNotifications={onOpenNotifications}
      />
    </Form>
  )
}

describe('Health', () => {
  it('keeps only the check schedule; alert events and repeat live under Notifications', () => {
    render(<Harness />)

    expect(screen.getByLabelText('How often to check')).toBeInTheDocument()
    expect(screen.queryByText('Alert on these events')).not.toBeInTheDocument()
    expect(screen.queryByText('Alert cooldown (minutes)')).not.toBeInTheDocument()
  })

  it('says a check never sends email', () => {
    render(<Harness />)

    expect(screen.getByText(/No email is sent, so it uses no quota/)).toBeInTheDocument()
  })

  it('hides the schedule while checks are off', () => {
    render(<Harness initialValues={{ ...baseValues, health_check_enabled: false }} />)

    expect(screen.getByLabelText('How often to check')).not.toBeVisible()
  })

  it('warns and links to Notifications when alerts have nowhere to go', async () => {
    const onOpenNotifications = vi.fn()
    render(<Harness alertsReady={false} onOpenNotifications={onOpenNotifications} />)

    expect(screen.getByText(WARNING)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Set up alerts' }))

    expect(onOpenNotifications).toHaveBeenCalledTimes(1)
  })

  it('warns when no connection event is ticked', () => {
    render(<Harness healthAlertsSelected={false} />)

    expect(screen.getByText(/No connection events are ticked/)).toBeInTheDocument()
  })

  it('does not warn while checks are off or alerts can be delivered', () => {
    const { unmount } = render(<Harness />)
    expect(screen.queryByText(WARNING)).not.toBeInTheDocument()
    unmount()

    render(
      <Harness initialValues={{ ...baseValues, health_check_enabled: false }} alertsReady={false} />
    )
    expect(screen.queryByText(WARNING)).not.toBeInTheDocument()
  })
})
