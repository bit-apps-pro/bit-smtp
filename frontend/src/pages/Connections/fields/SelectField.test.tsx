import { render, screen } from '@testing-library/react'
import { Form } from 'antd'
import { describe, expect, it } from 'vitest'
import SelectField from './SelectField'

describe('SelectField', () => {
  it('renders the provider options as selectable labels', () => {
    render(
      <Form>
        <SelectField
          field={{
            key: 'encryption',
            label: 'Encryption',
            type: 'select',
            options: [
              { value: 'tls', label: 'TLS' },
              { value: 'ssl', label: 'SSL' }
            ],
            required: true,
            secret: false,
            placeholder: 'Choose encryption',
            default: 'tls',
            dependsOn: null
          }}
        />
      </Form>
    )
    expect(screen.getByLabelText('Encryption')).toBeInTheDocument()
    expect(screen.getByText('Choose encryption')).toBeInTheDocument()
  })
})
