import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Form } from 'antd'
import { describe, expect, it, vi } from 'vitest'
import MaskedPasswordField from './MaskedPasswordField'

describe('MaskedPasswordField', () => {
  it('keeps the sentinel until edited, then submits the typed value', async () => {
    const onFinish = vi.fn()
    render(
      <Form onFinish={onFinish} initialValues={{ password: '********' }}>
        <MaskedPasswordField
          field={{
            key: 'password',
            label: 'Password',
            type: 'password',
            secret: true,
            required: false,
            placeholder: '',
            default: '',
            options: [],
            dependsOn: null
          }}
        />
        <button type="submit">save</button>
      </Form>
    )

    await userEvent.click(screen.getByText('save'))
    expect(onFinish).toHaveBeenCalledWith({ password: '********' })

    await userEvent.clear(screen.getByLabelText('Password'))
    await userEvent.type(screen.getByLabelText('Password'), 'newsecret')
    await userEvent.click(screen.getByText('save'))
    expect(onFinish).toHaveBeenLastCalledWith({ password: 'newsecret' })
  })
})
