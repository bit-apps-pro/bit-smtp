import { type EditableRoutingCondition, type MailSource } from '@pages/Routing/types'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import ConditionEditor from './ConditionEditor'

const condition: EditableRoutingCondition = {
  id: 'condition-1',
  field: 'recipient',
  operator: 'equals',
  value: 'a@b.com'
}

const sources: MailSource[] = [
  { value: 'woocommerce', label: 'WooCommerce' },
  { value: 'bit-form', label: 'Bit Form' }
]

describe('ConditionEditor', () => {
  it('renders the field, operator and value bound to the condition', () => {
    render(
      <ConditionEditor condition={condition} sources={sources} onChange={vi.fn()} onRemove={vi.fn()} />
    )

    expect(screen.getByRole('combobox', { name: 'Field' })).toBeInTheDocument()
    expect(screen.getByText('To recipient')).toBeInTheDocument()
    expect(screen.getByRole('combobox', { name: 'Operator' })).toBeInTheDocument()
    expect(screen.getByText('is exactly')).toBeInTheDocument()
    expect(screen.getByLabelText('Value')).toHaveValue('a@b.com')
  })

  it('calls onChange with the updated field when a new field is selected', async () => {
    const onChange = vi.fn()
    render(
      <ConditionEditor condition={condition} sources={sources} onChange={onChange} onRemove={vi.fn()} />
    )

    await userEvent.click(screen.getByRole('combobox', { name: 'Field' }))
    await userEvent.click(await screen.findByTitle('Subject'))

    expect(onChange).toHaveBeenCalledWith({ ...condition, field: 'subject', value: 'a@b.com' })
  })

  it('calls onChange with the updated operator when a new operator is selected', async () => {
    const onChange = vi.fn()
    render(
      <ConditionEditor condition={condition} sources={sources} onChange={onChange} onRemove={vi.fn()} />
    )

    await userEvent.click(screen.getByRole('combobox', { name: 'Operator' }))
    await userEvent.click(await screen.findByTitle('contains'))

    expect(onChange).toHaveBeenCalledWith({ ...condition, operator: 'contains' })
  })

  it('calls onChange with the updated value when the value input changes', async () => {
    const onChange = vi.fn()
    render(
      <ConditionEditor condition={condition} sources={sources} onChange={onChange} onRemove={vi.fn()} />
    )

    await userEvent.type(screen.getByLabelText('Value'), 'x')

    expect(onChange).toHaveBeenCalledWith({ ...condition, value: 'a@b.comx' })
  })

  it('calls onRemove when the remove button is clicked', async () => {
    const onRemove = vi.fn()
    render(
      <ConditionEditor condition={condition} sources={sources} onChange={vi.fn()} onRemove={onRemove} />
    )

    await userEvent.click(screen.getByRole('button', { name: 'Remove condition' }))

    expect(onRemove).toHaveBeenCalled()
  })

  it('renders an autocomplete of detected sources when the field is source_plugin', async () => {
    const sourceCondition: EditableRoutingCondition = {
      id: 'condition-2',
      field: 'source_plugin',
      operator: 'equals',
      value: ''
    }
    render(
      <ConditionEditor
        condition={sourceCondition}
        sources={sources}
        onChange={vi.fn()}
        onRemove={vi.fn()}
      />
    )

    const value = screen.getByRole('combobox', { name: 'Value' })
    await userEvent.click(value)

    expect(await screen.findByTitle('WooCommerce (woocommerce)')).toBeInTheDocument()
    expect(screen.getByTitle('Bit Form (bit-form)')).toBeInTheDocument()
  })

  it('emits the plugin slug when a source is picked from the autocomplete', async () => {
    const onChange = vi.fn()
    const sourceCondition: EditableRoutingCondition = {
      id: 'condition-2',
      field: 'source_plugin',
      operator: 'equals',
      value: ''
    }
    render(
      <ConditionEditor
        condition={sourceCondition}
        sources={sources}
        onChange={onChange}
        onRemove={vi.fn()}
      />
    )

    await userEvent.click(screen.getByRole('combobox', { name: 'Value' }))
    await userEvent.click(await screen.findByTitle('WooCommerce (woocommerce)'))

    expect(onChange).toHaveBeenCalledWith({ ...sourceCondition, value: 'woocommerce' })
  })

  it('lets an admin type a custom slug not present in the detected sources', async () => {
    const onChange = vi.fn()
    const sourceCondition: EditableRoutingCondition = {
      id: 'condition-2',
      field: 'source_plugin',
      operator: 'equals',
      value: ''
    }
    render(
      <ConditionEditor
        condition={sourceCondition}
        sources={sources}
        onChange={onChange}
        onRemove={vi.fn()}
      />
    )

    await userEvent.type(screen.getByRole('combobox', { name: 'Value' }), 'x')

    expect(onChange).toHaveBeenCalledWith({ ...sourceCondition, value: 'x' })
  })

  it('clears the value when switching a source_plugin condition to another field', async () => {
    const onChange = vi.fn()
    const sourceCondition: EditableRoutingCondition = {
      id: 'condition-2',
      field: 'source_plugin',
      operator: 'equals',
      value: 'woocommerce'
    }
    render(
      <ConditionEditor
        condition={sourceCondition}
        sources={sources}
        onChange={onChange}
        onRemove={vi.fn()}
      />
    )

    await userEvent.click(screen.getByRole('combobox', { name: 'Field' }))
    await userEvent.click(await screen.findByTitle('Subject'))

    expect(onChange).toHaveBeenCalledWith({ ...sourceCondition, field: 'subject', value: '' })
  })

  it('clears the value when switching another field into source_plugin', async () => {
    const onChange = vi.fn()
    render(
      <ConditionEditor condition={condition} sources={sources} onChange={onChange} onRemove={vi.fn()} />
    )

    await userEvent.click(screen.getByRole('combobox', { name: 'Field' }))
    await userEvent.click(await screen.findByTitle('Source plugin'))

    expect(onChange).toHaveBeenCalledWith({ ...condition, field: 'source_plugin', value: '' })
  })

  it('offers the domain operator for the recipient field', async () => {
    render(
      <ConditionEditor condition={condition} sources={sources} onChange={vi.fn()} onRemove={vi.fn()} />
    )

    await userEvent.click(screen.getByRole('combobox', { name: 'Operator' }))

    expect(await screen.findByTitle('domain is')).toBeInTheDocument()
  })

  it('offers the domain operator for the from field', async () => {
    const fromCondition: EditableRoutingCondition = {
      id: 'condition-3',
      field: 'from',
      operator: 'equals',
      value: ''
    }
    render(
      <ConditionEditor
        condition={fromCondition}
        sources={sources}
        onChange={vi.fn()}
        onRemove={vi.fn()}
      />
    )

    await userEvent.click(screen.getByRole('combobox', { name: 'Operator' }))

    expect(await screen.findByTitle('domain is')).toBeInTheDocument()
  })

  it('does not offer the domain operator for the subject field', async () => {
    const subjectCondition: EditableRoutingCondition = {
      id: 'condition-3',
      field: 'subject',
      operator: 'equals',
      value: ''
    }
    render(
      <ConditionEditor
        condition={subjectCondition}
        sources={sources}
        onChange={vi.fn()}
        onRemove={vi.fn()}
      />
    )

    await userEvent.click(screen.getByRole('combobox', { name: 'Operator' }))

    expect(await screen.findByTitle('contains')).toBeInTheDocument()
    expect(screen.queryByTitle('domain is')).not.toBeInTheDocument()
  })

  it('does not offer the domain operator for the source_plugin field', async () => {
    const sourceCondition: EditableRoutingCondition = {
      id: 'condition-3',
      field: 'source_plugin',
      operator: 'equals',
      value: ''
    }
    render(
      <ConditionEditor
        condition={sourceCondition}
        sources={sources}
        onChange={vi.fn()}
        onRemove={vi.fn()}
      />
    )

    await userEvent.click(screen.getByRole('combobox', { name: 'Operator' }))

    expect(await screen.findByTitle('contains')).toBeInTheDocument()
    expect(screen.queryByTitle('domain is')).not.toBeInTheDocument()
  })

  it('resets a domain operator to a valid default when switching to source_plugin', async () => {
    const onChange = vi.fn()
    const domainCondition: EditableRoutingCondition = {
      id: 'condition-3',
      field: 'recipient',
      operator: 'domain',
      value: 'example.com'
    }
    render(
      <ConditionEditor
        condition={domainCondition}
        sources={sources}
        onChange={onChange}
        onRemove={vi.fn()}
      />
    )

    await userEvent.click(screen.getByRole('combobox', { name: 'Field' }))
    await userEvent.click(await screen.findByTitle('Source plugin'))

    expect(onChange).toHaveBeenCalledWith({
      ...domainCondition,
      field: 'source_plugin',
      operator: 'equals',
      value: ''
    })
  })

  it.each([
    ['recipient', 'equals', 'customer@example.com'],
    ['subject', 'contains', 'invoice'],
    ['from', 'domain', 'example.com'],
    ['recipient', 'matches', '^noreply@.*\\.shop$']
  ] as const)('shows a %s/%s example placeholder', (field, operator, placeholder) => {
    render(
      <ConditionEditor
        condition={{ ...condition, field, operator, value: '' }}
        sources={sources}
        onChange={vi.fn()}
        onRemove={vi.fn()}
      />
    )

    expect(screen.getByLabelText('Value')).toHaveAttribute('placeholder', placeholder)
  })

  it('explains regex matching in a help popover only for the pattern operator', async () => {
    const { rerender } = render(
      <ConditionEditor condition={condition} sources={sources} onChange={vi.fn()} onRemove={vi.fn()} />
    )
    expect(screen.queryByRole('button', { name: 'Help: pattern matching' })).not.toBeInTheDocument()

    rerender(
      <ConditionEditor
        condition={{ ...condition, operator: 'matches', value: '^a' }}
        sources={sources}
        onChange={vi.fn()}
        onRemove={vi.fn()}
      />
    )
    await userEvent.click(screen.getByRole('button', { name: 'Help: pattern matching' }))

    expect(await screen.findByText(/appears anywhere in the field/)).toBeInTheDocument()
  })

  it('warns that an invalid pattern will never match', () => {
    render(
      <ConditionEditor
        condition={{ ...condition, operator: 'matches', value: '([a-z' }}
        sources={sources}
        onChange={vi.fn()}
        onRemove={vi.fn()}
      />
    )

    const warning = screen.getByText(/will never match/)
    expect(screen.getByLabelText('Value')).toHaveAttribute('aria-describedby', warning.id)
  })

  it('does not warn about a valid pattern', () => {
    render(
      <ConditionEditor
        condition={{ ...condition, operator: 'matches', value: '^noreply@' }}
        sources={sources}
        onChange={vi.fn()}
        onRemove={vi.fn()}
      />
    )

    expect(screen.queryByText(/will never match/)).not.toBeInTheDocument()
  })
})
