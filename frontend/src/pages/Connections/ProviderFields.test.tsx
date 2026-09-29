import { type FieldMeta } from '@pages/Connections/types'
import { render, screen } from '@testing-library/react'
import { Form } from 'antd'
import { describe, expect, it } from 'vitest'
import ProviderFields from './ProviderFields'

const fields: FieldMeta[] = [
  {
    key: 'auth',
    label: 'Auth',
    type: 'switch',
    default: true,
    required: false,
    secret: false,
    placeholder: '',
    options: [],
    dependsOn: null
  },
  {
    key: 'username',
    label: 'Username',
    type: 'text',
    required: false,
    secret: false,
    placeholder: '',
    default: '',
    options: [],
    dependsOn: { field: 'auth', value: true },
    requiredWhen: { field: 'auth', value: true }
  }
]

describe('ProviderFields', () => {
  it('hides dependent fields when the dependency value does not match', () => {
    render(
      <Form initialValues={{ auth: false }}>
        <ProviderFields fields={fields} />
      </Form>
    )

    expect(screen.getByText('Auth')).toBeInTheDocument()
    expect(screen.queryByText('Username')).not.toBeInTheDocument()
  })

  it('shows dependent fields when the dependency value matches', async () => {
    render(
      <Form initialValues={{ auth: true }}>
        <ProviderFields fields={fields} />
      </Form>
    )

    expect(await screen.findByText('Username')).toBeInTheDocument()
  })

  it('treats a truthy non-boolean value as on for both visibility and required-ness', async () => {
    render(
      <Form initialValues={{ auth: 1 }}>
        <ProviderFields fields={fields} />
      </Form>
    )

    const label = await screen.findByText('Username')
    expect(label.closest('label')).toHaveClass('ant-form-item-required')
  })
})
