import { render, screen } from '@testing-library/react'
import { Form } from 'antd'
import { describe, expect, it } from 'vitest'
import TextField from './TextField'

describe('TextField', () => {
  it('renders a text input bound to field.key', () => {
    render(
      <Form>
        <TextField
          field={{
            key: 'host',
            label: 'Host',
            type: 'text',
            options: [],
            required: false,
            secret: false,
            placeholder: 'smtp.example.com',
            default: '',
            dependsOn: null
          }}
        />
      </Form>
    )
    expect(screen.getByLabelText('Host')).toHaveAttribute('placeholder', 'smtp.example.com')
  })

  it('renders an email-typed input for the email field type', () => {
    render(
      <Form>
        <TextField
          field={{
            key: 'fromEmail',
            label: 'From Email',
            type: 'email',
            options: [],
            required: true,
            secret: false,
            placeholder: '',
            default: '',
            dependsOn: null
          }}
        />
      </Form>
    )
    expect(screen.getByLabelText('From Email')).toHaveAttribute('type', 'email')
  })
})
