import { render, screen } from '@testing-library/react'
import { Form } from 'antd'
import { describe, expect, it } from 'vitest'
import NumberField from './NumberField'

describe('NumberField', () => {
  it('renders a numeric input bound to field.key', () => {
    render(
      <Form initialValues={{ port: 587 }}>
        <NumberField
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
    expect(screen.getByLabelText('Port')).toHaveValue('587')
  })
})
