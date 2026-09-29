import { type PreferencesFormValues } from '@pages/Settings/types'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Form } from 'antd'
import { describe, expect, it, vi } from 'vitest'
import Reliability from './Reliability'

vi.mock('@pages/Settings/sections/RetryQueuePanel', () => ({ default: () => null }))

const baseValues: PreferencesFormValues = {
  logging_enabled: true,
  log_retention_days: 30,
  log_store_body: 'full',
  send_timeout_seconds: 30,
  retry_enabled: true,
  retry_max_attempts: 3,
  retry_backoff: 'exponential',
  retry_on_classes: ['transient', 'rate_limited'],
  health_check_enabled: false,
  health_check_interval: 'daily',
  notify_events: [],
  uninstall_purge: true,
  tracking_enabled: false
}

const FAILURE_GROUP = 'Which failures should be tried again?'

/** Mounts Reliability inside a real Form and echoes the live retry_on_classes value for assertions. */
function Harness({
  initialValues,
  cronDisabled
}: {
  initialValues: PreferencesFormValues
  cronDisabled?: boolean
}) {
  const [form] = Form.useForm<PreferencesFormValues>()
  const watched = Form.useWatch('retry_on_classes', form)

  return (
    <Form form={form} layout="vertical" initialValues={initialValues}>
      <Reliability form={form} cronDisabled={cronDisabled} />
      <div data-testid="watched-classes">{JSON.stringify(watched)}</div>
    </Form>
  )
}

describe('Reliability', () => {
  it('lists all five failure reasons and only lets the temporary ones be ticked', () => {
    render(<Harness initialValues={baseValues} />)

    const group = screen.getByRole('group', { name: FAILURE_GROUP })
    expect(
      within(group).getByRole('checkbox', { name: 'Temporary server or network problem' })
    ).toBeChecked()
    expect(within(group).getByRole('checkbox', { name: 'Sending limit reached' })).toBeChecked()
    expect(within(group).getByRole('checkbox', { name: 'Login or API key rejected' })).toBeDisabled()
    expect(
      within(group).getByRole('checkbox', { name: "Recipient address doesn't exist" })
    ).toBeDisabled()
    expect(within(group).getByRole('checkbox', { name: 'Rejected by provider' })).toBeDisabled()
  })

  it('writes an unticked reason back to retry_on_classes', async () => {
    render(<Harness initialValues={baseValues} />)

    await userEvent.click(screen.getByRole('checkbox', { name: 'Temporary server or network problem' }))

    expect(screen.getByTestId('watched-classes')).toHaveTextContent(JSON.stringify(['rate_limited']))
  })

  it('hides the retry details while automatic retry is off', () => {
    render(<Harness initialValues={{ ...baseValues, retry_enabled: false }} />)

    expect(screen.getByRole('group', { name: FAILURE_GROUP, hidden: true })).not.toBeVisible()
    expect(screen.getByLabelText('Number of tries')).not.toBeVisible()
  })

  it('previews when the tries will happen for the chosen wait', async () => {
    render(<Harness initialValues={baseValues} />)

    expect(
      screen.getByText('Tries again about 5 min, 15 min, and 35 min after the first failure.')
    ).toBeInTheDocument()

    await userEvent.click(screen.getByRole('radio', { name: 'Same wait every time' }))

    expect(
      screen.getByText('Tries again about 5 min, 10 min, and 15 min after the first failure.')
    ).toBeInTheDocument()
  })

  it('warns that retries need a real cron job when WP-Cron is off', () => {
    render(<Harness initialValues={baseValues} cronDisabled />)

    expect(screen.getByText(/WP-Cron\) are off on this site/)).toBeInTheDocument()
  })
})
