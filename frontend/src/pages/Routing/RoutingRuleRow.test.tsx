import { type Connection } from '@pages/Connections/types'
import { type EditableRoutingRule, type MailSourceCatalog } from '@pages/Routing/types'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import RoutingRuleRow from './RoutingRuleRow'

const connections: Connection[] = [
  {
    id: 'conn_1',
    provider: 'other_smtp',
    kind: 'smtp',
    name: 'Primary SMTP',
    enabled: true,
    fromEmail: 'a@b.c',
    fromName: 'A',
    replyToEmail: '',
    settings: {},
    credentials: {}
  },
  {
    id: 'conn_2',
    provider: 'other_smtp',
    kind: 'smtp',
    name: 'Backup SMTP',
    enabled: true,
    fromEmail: 'd@e.f',
    fromName: 'D',
    replyToEmail: '',
    settings: {},
    credentials: {}
  }
]

const rule: EditableRoutingRule = {
  id: 'rule-1',
  connectionId: 'conn_1',
  conditions: [{ id: 'condition-1', field: 'recipient', operator: 'equals', value: 'a@b.com' }]
}

const sources: MailSourceCatalog = {
  seen: [{ value: 'woocommerce', label: 'WooCommerce' }],
  installed: []
}

describe('RoutingRuleRow', () => {
  it('renders the target connection and one ConditionEditor per condition', () => {
    render(
      <RoutingRuleRow
        rule={rule}
        connections={connections}
        sources={sources}
        onChange={vi.fn()}
        onRemove={vi.fn()}
      />
    )

    expect(screen.getByText('Primary SMTP')).toBeInTheDocument()
    expect(screen.getByLabelText('Value')).toHaveValue('a@b.com')
  })

  it('calls onChange with the new connectionId when the target connection changes', async () => {
    const onChange = vi.fn()
    render(
      <RoutingRuleRow
        rule={rule}
        connections={connections}
        sources={sources}
        onChange={onChange}
        onRemove={vi.fn()}
      />
    )

    await userEvent.click(screen.getByRole('combobox', { name: 'Target connection' }))
    await userEvent.click(await screen.findByTitle('Backup SMTP'))

    expect(onChange).toHaveBeenCalledWith({ ...rule, connectionId: 'conn_2' })
  })

  it('adds an empty condition when Add condition is clicked', async () => {
    const onChange = vi.fn()
    render(
      <RoutingRuleRow
        rule={rule}
        connections={connections}
        sources={sources}
        onChange={onChange}
        onRemove={vi.fn()}
      />
    )

    await userEvent.click(screen.getByRole('button', { name: /Add condition/ }))

    expect(onChange).toHaveBeenCalledWith(
      expect.objectContaining({
        conditions: [
          rule.conditions[0],
          expect.objectContaining({ field: 'recipient', operator: 'equals', value: '' })
        ]
      })
    )
  })

  it('removes a condition when its remove button is clicked', async () => {
    const onChange = vi.fn()
    render(
      <RoutingRuleRow
        rule={rule}
        connections={connections}
        sources={sources}
        onChange={onChange}
        onRemove={vi.fn()}
      />
    )

    await userEvent.click(screen.getByRole('button', { name: 'Remove condition' }))

    expect(onChange).toHaveBeenCalledWith({ ...rule, conditions: [] })
  })

  it('calls onRemove when Delete rule is confirmed', async () => {
    const onRemove = vi.fn()
    render(
      <RoutingRuleRow
        rule={rule}
        connections={connections}
        sources={sources}
        onChange={vi.fn()}
        onRemove={onRemove}
      />
    )

    await userEvent.click(screen.getByRole('button', { name: /Delete rule/ }))
    expect(await screen.findByText(/your primary connection/)).toBeInTheDocument()
    const popconfirm = screen.getByText(/your primary connection/).closest('.ant-popover') as HTMLElement
    await userEvent.click(within(popconfirm).getByRole('button', { name: 'Delete rule' }))

    expect(onRemove).toHaveBeenCalled()
  })

  /** Renders the row for `rule` with inert callbacks. */
  const renderRule = (overrides: Partial<EditableRoutingRule>, rowConnections = connections) =>
    render(
      <RoutingRuleRow
        rule={{ ...rule, ...overrides }}
        connections={rowConnections}
        sources={sources}
        onChange={vi.fn()}
        onRemove={vi.fn()}
      />
    )

  it('states that all conditions must match and joins them with "and"', () => {
    renderRule({
      conditions: [
        ...rule.conditions,
        { id: 'condition-2', field: 'subject', operator: 'contains', value: 'order' }
      ]
    })

    expect(screen.getByText('Send it here when all of these conditions match')).toBeInTheDocument()
    expect(screen.getAllByText('and')).toHaveLength(1)
  })

  it('warns that a rule without conditions never matches', () => {
    renderRule({ conditions: [] })

    expect(
      screen.getByText('This rule has no conditions, so it never runs. Add a condition.')
    ).toBeInTheDocument()
  })

  it('marks a disabled target connection as skipped', () => {
    renderRule({}, [{ ...connections[0], enabled: false }])

    expect(screen.getByText('Primary SMTP (disabled)')).toBeInTheDocument()
  })
})
