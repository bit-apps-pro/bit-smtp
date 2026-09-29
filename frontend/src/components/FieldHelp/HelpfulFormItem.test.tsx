import { fireEvent, render, screen } from '@testing-library/react'
import { Form, Input } from 'antd'
import { describe, expect, it } from 'vitest'
import HelpfulFormItem from './HelpfulFormItem'

describe('HelpfulFormItem', () => {
  it('renders the hint under the control and passes Form.Item props through', () => {
    render(
      <Form initialValues={{ timeout: '30' }}>
        <HelpfulFormItem name="timeout" label="Send timeout" hint="Between 5 and 120 seconds.">
          <Input />
        </HelpfulFormItem>
      </Form>
    )

    expect(screen.getByText('Between 5 and 120 seconds.')).toBeInTheDocument()
    expect(screen.getByLabelText('Send timeout')).toHaveValue('30')
    expect(screen.queryByRole('button', { name: /Help/ })).not.toBeInTheDocument()
  })

  it('adds a help popover after the label when details are given', async () => {
    render(
      <Form>
        <HelpfulFormItem
          name="backoff"
          label="Wait between retries"
          details={{ title: 'Retry timing', body: 'How long we wait.' }}
        >
          <Input />
        </HelpfulFormItem>
      </Form>
    )

    fireEvent.click(screen.getByRole('button', { name: 'Help: Wait between retries' }))

    expect(await screen.findByText('Retry timing')).toBeInTheDocument()
  })

  it('uses helpLabel as the popover button name when provided', () => {
    render(
      <Form>
        <HelpfulFormItem
          name="x"
          label="Types"
          helpLabel="How errors are classified"
          details={{ title: 'T', body: 'B' }}
        >
          <Input />
        </HelpfulFormItem>
      </Form>
    )

    expect(screen.getByRole('button', { name: 'How errors are classified' })).toBeInTheDocument()
  })
})
