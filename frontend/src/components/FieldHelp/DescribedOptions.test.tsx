import { useState } from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import { Form } from 'antd'
import { describe, expect, it, vi } from 'vitest'
import DescribedOptions, { type DescribedOption } from './DescribedOptions'

const OPTIONS: DescribedOption[] = [
  { value: 'transient', label: 'Temporary problem', description: 'Usually fixes itself.' },
  {
    value: 'auth',
    label: 'Login rejected',
    description: 'Fix credentials.',
    disabled: true,
    disabledReason: 'Never retried.'
  }
]

describe('DescribedOptions', () => {
  it('renders each option with its description as the accessible description', () => {
    render(<DescribedOptions type="checkbox" options={OPTIONS} value={[]} />)

    expect(screen.getByRole('checkbox', { name: 'Temporary problem' })).toHaveAccessibleDescription(
      'Usually fixes itself.'
    )
  })

  it('shows the disabled reason instead of the description for a disabled option', () => {
    render(<DescribedOptions type="checkbox" options={OPTIONS} value={[]} />)

    const auth = screen.getByRole('checkbox', { name: 'Login rejected' })
    expect(auth).toBeDisabled()
    expect(auth).toHaveAccessibleDescription('Never retried.')
    expect(screen.queryByText('Fix credentials.')).not.toBeInTheDocument()
  })

  it('reports checkbox value changes', () => {
    const onChange = vi.fn()
    render(<DescribedOptions type="checkbox" options={OPTIONS} value={[]} onChange={onChange} />)

    fireEvent.click(screen.getByRole('checkbox', { name: 'Temporary problem' }))

    expect(onChange).toHaveBeenCalledWith(['transient'])
  })

  it('reports radio value changes', () => {
    const onChange = vi.fn()
    render(
      <DescribedOptions
        type="radio"
        options={[
          { value: 'none', label: 'None' },
          { value: 'full', label: 'Full', description: 'Store the whole body.' }
        ]}
        value="none"
        onChange={onChange}
      />
    )

    fireEvent.click(screen.getByRole('radio', { name: 'Full' }))

    expect(onChange).toHaveBeenCalledWith('full')
  })

  it('disables every option and explains why when the group is disabled', () => {
    render(
      <DescribedOptions
        type="checkbox"
        aria-label="Failure types"
        options={OPTIONS}
        value={[]}
        disabled
        disabledReason="Turn on retries first."
      />
    )

    screen.getAllByRole('checkbox').forEach(box => expect(box).toBeDisabled())
    expect(screen.getByRole('group', { name: 'Failure types' })).toHaveAccessibleDescription(
      'Turn on retries first.'
    )
  })

  it('works as a controlled field inside Form.Item', () => {
    /** Form wrapper that mirrors the field value into the DOM for assertions. */
    function Harness() {
      const [values, setValues] = useState<string[]>([])
      return (
        <Form onValuesChange={(_, all: { classes: string[] }) => setValues(all.classes)}>
          <Form.Item name="classes" initialValue={['transient']}>
            <DescribedOptions
              type="checkbox"
              options={[...OPTIONS, { value: 'permanent', label: 'Rejected' }]}
            />
          </Form.Item>
          <output>{values.join(',')}</output>
        </Form>
      )
    }
    render(<Harness />)

    expect(screen.getByRole('checkbox', { name: 'Temporary problem' })).toBeChecked()
    fireEvent.click(screen.getByRole('checkbox', { name: 'Rejected' }))

    expect(screen.getByRole('status')).toHaveTextContent('transient,permanent')
  })
})
