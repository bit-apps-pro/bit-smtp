import { type PreferencesFormValues } from '@pages/Settings/types'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Form } from 'antd'
import { describe, expect, it } from 'vitest'
import Health from './Health'

const baseValues: PreferencesFormValues = {
  logging_enabled: true,
  log_retention_days: 30,
  log_store_body: 'full',
  send_timeout_seconds: 30,
  retry_enabled: false,
  retry_max_attempts: 3,
  retry_backoff: 'exponential',
  retry_on_classes: [],
  health_check_enabled: true,
  health_check_interval: 'daily',
  notify_cooldown_minutes: 15,
  notify_events: ['connection_unhealthy', 'oauth_expired'],
  uninstall_purge: true,
  tracking_enabled: false
}

/** Mounts Health inside a real Form and echoes the live notify_events value for binding assertions. */
function Harness({ initialValues }: { initialValues: PreferencesFormValues }) {
  const [form] = Form.useForm<PreferencesFormValues>()
  const watchedEvents = Form.useWatch('notify_events', form)

  return (
    <Form form={form} layout="vertical" initialValues={initialValues}>
      <Health form={form} />
      <div data-testid="watched-events">{JSON.stringify(watchedEvents)}</div>
    </Form>
  )
}

/** The antd Select container wrapping a given labelled combobox, for scoping tag/option queries. */
function selectContainer(label: string): HTMLElement {
  return screen.getByLabelText(label).closest('.ant-select') as HTMLElement
}

describe('Health', () => {
  it('renders the alert-events control bound to the stored notify_events selection', () => {
    render(<Harness initialValues={baseValues} />)

    const control = selectContainer('Alert on these events')
    expect(within(control).getByText('Connection unhealthy')).toBeInTheDocument()
    expect(within(control).getByText('OAuth token expired')).toBeInTheDocument()
    expect(within(control).queryByText('Connection recovered')).not.toBeInTheDocument()
  })

  it('renders the relocated cooldown field with its saved value', () => {
    render(<Harness initialValues={baseValues} />)

    expect((screen.getByLabelText('Alert cooldown (minutes)') as HTMLInputElement).value).toBe('15')
  })

  it('disables the events and cooldown controls until health checks are enabled', () => {
    render(<Harness initialValues={{ ...baseValues, health_check_enabled: false }} />)

    expect(screen.getByLabelText('Alert on these events')).toBeDisabled()
    expect(screen.getByLabelText('Alert cooldown (minutes)')).toBeDisabled()
  })

  it('enables the events and cooldown controls once health checks are on', () => {
    render(<Harness initialValues={baseValues} />)

    expect(screen.getByLabelText('Alert on these events')).not.toBeDisabled()
    expect(screen.getByLabelText('Alert cooldown (minutes)')).not.toBeDisabled()
  })

  it('writes the chosen event back to notify_events on the form', async () => {
    render(<Harness initialValues={{ ...baseValues, notify_events: ['connection_unhealthy'] }} />)

    await userEvent.click(screen.getByLabelText('Alert on these events'))
    await userEvent.click(await screen.findByText('OAuth token expiring'))

    expect(screen.getByTestId('watched-events')).toHaveTextContent(
      JSON.stringify(['connection_unhealthy', 'oauth_expiring'])
    )
  })

  it('warns that alerts are off when health checks are on but no events are selected', () => {
    render(<Harness initialValues={{ ...baseValues, health_check_enabled: true, notify_events: [] }} />)

    expect(
      screen.getByText('No events selected — connection health alerts are off.')
    ).toBeInTheDocument()
  })

  it('hides the no-events warning once at least one event is selected', () => {
    render(<Harness initialValues={{ ...baseValues, notify_events: ['connection_unhealthy'] }} />)

    expect(
      screen.queryByText('No events selected — connection health alerts are off.')
    ).not.toBeInTheDocument()
  })

  it('does not warn about missing events while health checks are disabled', () => {
    render(<Harness initialValues={{ ...baseValues, health_check_enabled: false, notify_events: [] }} />)

    expect(
      screen.queryByText('No events selected — connection health alerts are off.')
    ).not.toBeInTheDocument()
  })
})
