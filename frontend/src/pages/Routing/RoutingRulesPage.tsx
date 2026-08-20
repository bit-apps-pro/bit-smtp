import { useEffect, useState } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import notify from '@components/Toaster/Toaster'
import {
  DndContext,
  type DragEndEvent,
  PointerSensor,
  closestCenter,
  useSensor,
  useSensors
} from '@dnd-kit/core'
import { SortableContext, arrayMove, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import useMailSettings from '@pages/Connections/data/useMailSettings'
import useUpdateSettings from '@pages/Connections/data/useUpdateSettings'
import { type MailSettings } from '@pages/Connections/types'
import useMailSources from '@pages/Routing/data/useMailSources'
import { type EditableRoutingRule, type MailSource, type RoutingRule } from '@pages/Routing/types'
import { Button, Flex, Spin, Typography, theme } from 'antd'
import RoutingRuleRow from './RoutingRuleRow'

const { Title, Text } = Typography

let nextRuleId = 0
function createRuleId(): string {
  nextRuleId += 1
  return `rule-${nextRuleId}`
}

function emptyRule(): EditableRoutingRule {
  return {
    id: createRuleId(),
    connectionId: '',
    conditions: [{ id: createRuleId(), field: 'recipient', operator: 'equals', value: '' }]
  }
}

// features.routing is untyped JSON from the backend; trust the R1/R3 stored shape.
function readRoutingRules(features: Record<string, unknown>): RoutingRule[] {
  return Array.isArray(features.routing) ? (features.routing as RoutingRule[]) : []
}

function toEditableRules(settings: MailSettings): EditableRoutingRule[] {
  return readRoutingRules(settings.features).map(rule => ({
    ...rule,
    id: createRuleId(),
    conditions: rule.conditions.map(condition => ({ ...condition, id: createRuleId() }))
  }))
}

function toStoredRules(rules: EditableRoutingRule[]): RoutingRule[] {
  return rules.map(({ connectionId, conditions }) => ({
    connectionId,
    conditions: conditions.map(({ field, operator, value }) => ({ field, operator, value }))
  }))
}

/** Draggable wrapper: plumbs @dnd-kit's sortable handle into RoutingRuleRow, keyed on the rule's stable id. */
function SortableRuleRow({
  rule,
  connections,
  sources,
  priority,
  onChange,
  onRemove
}: {
  rule: EditableRoutingRule
  connections: MailSettings['connections']
  sources: MailSource[]
  priority: number
  onChange: (rule: EditableRoutingRule) => void
  onRemove: () => void
}) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: rule.id
  })

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : 1
  }

  return (
    <div ref={setNodeRef} style={style}>
      <RoutingRuleRow
        rule={rule}
        connections={connections}
        sources={sources}
        priority={priority}
        onChange={onChange}
        onRemove={onRemove}
        dragHandleAttributes={attributes}
        dragHandleListeners={listeners}
      />
    </div>
  )
}

export default function RoutingRulesPage() {
  const { token } = theme.useToken()
  const { data: settings, isPending } = useMailSettings()
  const { data: sources } = useMailSources()
  const updateSettings = useUpdateSettings()
  const [rules, setRules] = useState<EditableRoutingRule[]>([])
  const sensors = useSensors(useSensor(PointerSensor))

  useEffect(() => {
    if (settings) {
      setRules(toEditableRules(settings))
    }
  }, [settings])

  if (isPending) {
    return (
      <Flex justify="center" style={{ padding: 48 }}>
        <Spin size="large" />
      </Flex>
    )
  }

  if (!settings) {
    return null
  }

  const updateRule = (index: number, rule: EditableRoutingRule) => {
    setRules(rules.map((item, itemIndex) => (itemIndex === index ? rule : item)))
  }

  const removeRule = (index: number) => {
    setRules(rules.filter((_, itemIndex) => itemIndex !== index))
  }

  const addRule = () => {
    setRules([...rules, emptyRule()])
  }

  /** Reorders rules locally on drop; persistence happens on the existing Save click. */
  const handleDragEnd = (event: DragEndEvent) => {
    const { active, over } = event
    if (!over || active.id === over.id) {
      return
    }

    const oldIndex = rules.findIndex(rule => rule.id === active.id)
    const newIndex = rules.findIndex(rule => rule.id === over.id)
    if (oldIndex === -1 || newIndex === -1) {
      return
    }

    setRules(arrayMove(rules, oldIndex, newIndex))
  }

  const handleSave = () => {
    updateSettings.mutate(
      { features: { ...settings.features, routing: toStoredRules(rules) } },
      { onSuccess: () => notify.success(__('Routing rules saved')) }
    )
  }

  return (
    <Flex vertical gap="middle" style={{ padding: token.paddingLG }}>
      <Flex justify="space-between" align="center">
        <Title level={4} style={{ margin: 0 }}>
          {__('Routing')}
        </Title>
        <Button type="primary" onClick={handleSave} loading={updateSettings.isPending}>
          {__('Save')}
        </Button>
      </Flex>
      <Text type="secondary">
        {__('The first matching rule wins. If no rule matches, mail uses the default connection.')}
      </Text>
      <Text type="secondary" style={{ fontSize: token.fontSizeSM }}>
        {__('Drag rules to reorder — rules are evaluated top to bottom.')}
      </Text>
      <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
        <SortableContext items={rules.map(rule => rule.id)} strategy={verticalListSortingStrategy}>
          <Flex vertical gap="middle">
            {rules.map((rule, index) => (
              <SortableRuleRow
                key={rule.id}
                rule={rule}
                connections={settings.connections}
                sources={sources ?? []}
                priority={index + 1}
                onChange={updated => updateRule(index, updated)}
                onRemove={() => removeRule(index)}
              />
            ))}
          </Flex>
        </SortableContext>
      </DndContext>
      <Button type="dashed" onClick={addRule}>
        {__('Add rule')}
      </Button>
    </Flex>
  )
}
