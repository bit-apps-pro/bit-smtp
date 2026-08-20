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
    dependsOn: { field: 'auth', value: true }
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
})
