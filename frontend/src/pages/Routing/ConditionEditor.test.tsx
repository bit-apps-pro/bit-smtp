import { type EditableRoutingCondition } from '@pages/Routing/types'
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

describe('ConditionEditor', () => {
  it('renders the field, operator and value bound to the condition', () => {
    render(<ConditionEditor condition={condition} onChange={vi.fn()} onRemove={vi.fn()} />)

    expect(screen.getByRole('combobox', { name: 'Field' })).toBeInTheDocument()
    expect(screen.getByText('Recipient')).toBeInTheDocument()
    expect(screen.getByRole('combobox', { name: 'Operator' })).toBeInTheDocument()
    expect(screen.getByText('Equals')).toBeInTheDocument()
    expect(screen.getByLabelText('Value')).toHaveValue('a@b.com')
  })

  it('calls onChange with the updated field when a new field is selected', async () => {
    const onChange = vi.fn()
    render(<ConditionEditor condition={condition} onChange={onChange} onRemove={vi.fn()} />)

    await userEvent.click(screen.getByRole('combobox', { name: 'Field' }))
    await userEvent.click(await screen.findByTitle('Subject'))

    expect(onChange).toHaveBeenCalledWith({ ...condition, field: 'subject' })
  })

  it('calls onChange with the updated operator when a new operator is selected', async () => {
    const onChange = vi.fn()
    render(<ConditionEditor condition={condition} onChange={onChange} onRemove={vi.fn()} />)

    await userEvent.click(screen.getByRole('combobox', { name: 'Operator' }))
    await userEvent.click(await screen.findByTitle('Contains'))

    expect(onChange).toHaveBeenCalledWith({ ...condition, operator: 'contains' })
  })

  it('calls onChange with the updated value when the value input changes', async () => {
    const onChange = vi.fn()
    render(<ConditionEditor condition={condition} onChange={onChange} onRemove={vi.fn()} />)

    await userEvent.type(screen.getByLabelText('Value'), 'x')

    expect(onChange).toHaveBeenCalledWith({ ...condition, value: 'a@b.comx' })
  })

  it('calls onRemove when the remove button is clicked', async () => {
    const onRemove = vi.fn()
    render(<ConditionEditor condition={condition} onChange={vi.fn()} onRemove={onRemove} />)

    await userEvent.click(screen.getByRole('button', { name: 'Remove condition' }))

    expect(onRemove).toHaveBeenCalled()
  })
})
