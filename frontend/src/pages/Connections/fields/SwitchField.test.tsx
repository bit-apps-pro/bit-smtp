import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Form } from 'antd'
import { describe, expect, it, vi } from 'vitest'
import SwitchField from './SwitchField'

describe('SwitchField', () => {
  it('toggles the boolean value bound to field.key', async () => {
    const onFinish = vi.fn()
    render(
      <Form onFinish={onFinish} initialValues={{ enabled: false }}>
        <SwitchField
          field={{
            key: 'enabled',
            label: 'Enabled',
            type: 'switch',
            options: [],
            required: false,
            secret: false,
            placeholder: '',
            default: false,
            dependsOn: null
          }}
        />
        <button type="submit">save</button>
      </Form>
    )

    await userEvent.click(screen.getByRole('switch', { name: 'Enabled' }))
    await userEvent.click(screen.getByText('save'))
    expect(onFinish).toHaveBeenCalledWith({ enabled: true })
  })
})
