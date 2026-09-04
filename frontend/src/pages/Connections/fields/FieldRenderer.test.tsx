import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Form } from 'antd'
import { describe, expect, it } from 'vitest'
import FieldRenderer from './FieldRenderer'

describe('FieldRenderer', () => {
  it('renders a select with provider options', () => {
    render(
      <Form>
        <FieldRenderer
          field={{
            key: 'encryption',
            label: 'Encryption',
            type: 'select',
            options: [{ value: 'tls', label: 'TLS' }],
            required: true,
            secret: false,
            placeholder: '',
            default: 'tls',
            dependsOn: null
          }}
        />
      </Form>
    )
    expect(screen.getByText('Encryption')).toBeInTheDocument()
  })

  it('renders a switch toggle', () => {
    render(
      <Form>
        <FieldRenderer
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
      </Form>
    )
    expect(screen.getByRole('switch', { name: 'Enabled' })).toBeInTheDocument()
  })

  it('renders a numeric input', () => {
    render(
      <Form>
        <FieldRenderer
          field={{
            key: 'port',
            label: 'Port',
            type: 'number',
            options: [],
            required: true,
            secret: false,
            placeholder: '587',
            default: 587,
            dependsOn: null
          }}
        />
      </Form>
    )
    expect(screen.getByLabelText('Port')).toBeInTheDocument()
  })

  it('renders a text input', () => {
    render(
      <Form>
        <FieldRenderer
          field={{
            key: 'host',
            label: 'Host',
            type: 'text',
            options: [],
            required: true,
            secret: false,
            placeholder: 'smtp.example.com',
            default: '',
            dependsOn: null
          }}
        />
      </Form>
    )
    expect(screen.getByLabelText('Host')).toBeInTheDocument()
  })

  it('renders a masked password input', () => {
    render(
      <Form>
        <FieldRenderer
          field={{
            key: 'password',
            label: 'Password',
            type: 'password',
            options: [],
            required: false,
            secret: true,
            placeholder: '',
            default: '',
            dependsOn: null
          }}
        />
      </Form>
    )
    expect(screen.getByLabelText('Password')).toHaveAttribute('type', 'password')
  })

  it('shows linked provider guidance in the field tooltip', async () => {
    render(
      <Form>
        <FieldRenderer
          field={{
            key: 'client_id',
            label: 'Application (client) ID',
            type: 'text',
            options: [],
            required: true,
            secret: false,
            placeholder: '',
            default: '',
            dependsOn: null,
            help: {
              text: 'Copy the value from the app Overview page.',
              url: 'https://entra.microsoft.com/',
              linkLabel: 'Open Microsoft Entra'
            }
          }}
        />
      </Form>
    )

    await userEvent.hover(screen.getByRole('img', { name: 'question-circle' }))

    expect(await screen.findByText('Copy the value from the app Overview page.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Open Microsoft Entra' })).toHaveAttribute(
      'href',
      'https://entra.microsoft.com/'
    )
  })

  it('masks a non-password field whose metadata marks it secret', () => {
    render(
      <Form>
        <FieldRenderer
          field={{
            key: 'apiKey',
            label: 'API Key',
            type: 'text',
            options: [],
            required: false,
            secret: true,
            placeholder: '',
            default: '',
            dependsOn: null
          }}
        />
      </Form>
    )
    expect(screen.getByLabelText('API Key')).toHaveAttribute('type', 'password')
  })
})
