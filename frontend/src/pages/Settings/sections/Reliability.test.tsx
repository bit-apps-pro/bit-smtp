import { type PreferencesFormValues } from '@pages/Settings/types'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Form } from 'antd'
import { describe, expect, it, vi } from 'vitest'
import Reliability from './Reliability'

// The live retry-queue panel fetches over the network; stub it so this suite exercises only the
// preference fields.
vi.mock('@pages/Settings/sections/RetryQueuePanel', () => ({ default: () => null }))

const baseValues: PreferencesFormValues = {
  logging_enabled: true,
  log_retention_days: 30,
  log_store_body: 'full',
  send_timeout_seconds: 30,
  retry_enabled: true,
  retry_max_attempts: 3,
  retry_backoff: 'exponential',
  retry_on_classes: [],
  health_check_enabled: false,
  health_check_interval: 'daily',
  notify_cooldown_minutes: 0,
  notify_events: [],
  uninstall_purge: true,
  tracking_enabled: false
}

/** Mounts Reliability inside a real Form and echoes the live retry_on_classes value for assertions. */
function Harness({ initialValues }: { initialValues: PreferencesFormValues }) {
  const [form] = Form.useForm<PreferencesFormValues>()
  const watched = Form.useWatch('retry_on_classes', form)

  return (
    <Form form={form} layout="vertical" initialValues={initialValues}>
      <Reliability form={form} />
      <div data-testid="watched-classes">{JSON.stringify(watched)}</div>
    </Form>
  )
}

/** The antd Select container wrapping a given labelled combobox, for scoping tag/option queries. */
function selectContainer(label: string): HTMLElement {
  return screen.getByLabelText(label).closest('.ant-select') as HTMLElement
}

describe('Reliability', () => {
  it('renders the retry-class filter bound to the stored selection', () => {
    render(<Harness initialValues={{ ...baseValues, retry_on_classes: ['rate_limited'] }} />)

    const control = selectContainer('Retry only these failure types')
    expect(within(control).getByText('Rate limited')).toBeInTheDocument()
    expect(within(control).queryByText('Transient errors')).not.toBeInTheDocument()
  })

  it('disables the retry-class filter until automatic retry is enabled', () => {
    render(<Harness initialValues={{ ...baseValues, retry_enabled: false }} />)

    expect(screen.getByLabelText('Retry only these failure types')).toBeDisabled()
  })

  it('writes the chosen failure class back to retry_on_classes', async () => {
    render(<Harness initialValues={baseValues} />)

    await userEvent.click(screen.getByLabelText('Retry only these failure types'))
    await userEvent.click(await screen.findByText('Transient errors'))

    expect(screen.getByTestId('watched-classes')).toHaveTextContent(JSON.stringify(['transient']))
  })
})
