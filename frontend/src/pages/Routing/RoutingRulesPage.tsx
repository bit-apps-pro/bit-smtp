import { useEffect, useState } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import useUnsavedChangesGuard from '@common/hooks/useUnsavedChangesGuard'
import notify from '@components/Toaster/Toaster'
import {
  DndContext,
  type DragEndEvent,
  KeyboardSensor,
  PointerSensor,
  closestCenter,
  useSensor,
  useSensors
} from '@dnd-kit/core'
import {
  SortableContext,
  arrayMove,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy
} from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import useMailSettings from '@pages/Connections/data/useMailSettings'
import useUpdateSettings from '@pages/Connections/data/useUpdateSettings'
import { type MailSettings } from '@pages/Connections/types'
import useMailSources from '@pages/Routing/data/useMailSources'
import { readRoutingRules, sameStoredRules, toStoredRules } from '@pages/Routing/routingRules'
import { type EditableRoutingRule, type MailSource } from '@pages/Routing/types'
import { Button, Flex, Modal, Spin, Typography, theme } from 'antd'
import RoutingRuleRow from './RoutingRuleRow'

const { Title, Text } = Typography

let nextRuleId = 0
/** Client-side-only id for a newly added rule or condition. */
function createRuleId(): string {
  nextRuleId += 1
  return `rule-${nextRuleId}`
}

/** Blank rule seeded with one default condition. */
function emptyRule(): EditableRoutingRule {
  return {
    id: createRuleId(),
    connectionId: '',
    conditions: [{ id: createRuleId(), field: 'recipient', operator: 'equals', value: '' }]
  }
}

/** Converts stored routing rules into the editable, client-id-keyed form the page works with. */
function toEditableRules(settings: MailSettings): EditableRoutingRule[] {
  return readRoutingRules(settings.features).map(rule => ({
    ...rule,
    id: createRuleId(),
    conditions: rule.conditions.map(condition => ({ ...condition, id: createRuleId() }))
  }))
}

/** Dashed-border empty state explaining what routing is for, with the first "Add rule" action. */
function EmptyRules({ onAddRule }: { onAddRule: () => void }) {
  const { token } = theme.useToken()

  return (
    <Flex
      vertical
      align="center"
      gap="small"
      style={{
        padding: '48px 24px',
        textAlign: 'center',
        border: `1px dashed ${token.colorBorderSecondary}`,
        borderRadius: token.borderRadiusLG
      }}
    >
      <Title level={5} style={{ margin: 0 }}>
        {__('No routing rules')}
      </Title>
      <Text type="secondary" style={{ maxWidth: 440 }}>
        {__(
          'All mail uses your primary connection. Add a rule to send specific mail, such as WooCommerce orders, through a different connection.'
        )}
      </Text>
      <Button type="primary" onClick={onAddRule} style={{ marginTop: token.marginXS }}>
        {__('Add rule')}
      </Button>
    </Flex>
  )
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

/** Routing rules page: ordered, drag/keyboard-reorderable match rules with an explicit Save and an unsaved-changes guard. */
export default function RoutingRulesPage() {
  const { token } = theme.useToken()
  const { data: settings, isPending } = useMailSettings()
  const { data: sources } = useMailSources()
  const updateSettings = useUpdateSettings()
  const [rules, setRules] = useState<EditableRoutingRule[]>(() =>
    settings ? toEditableRules(settings) : []
  )
  const sensors = useSensors(
    useSensor(PointerSensor),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates })
  )
  const isDirty = settings ? !sameStoredRules(rules, readRoutingRules(settings.features)) : false
  const guard = useUnsavedChangesGuard(isDirty)

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

  /** Replaces the rule at `index`. */
  const updateRule = (index: number, rule: EditableRoutingRule) => {
    setRules(rules.map((item, itemIndex) => (itemIndex === index ? rule : item)))
  }

  /** Drops the rule at `index`. */
  const removeRule = (index: number) => {
    setRules(rules.filter((_, itemIndex) => itemIndex !== index))
  }

  /** Appends a blank rule. */
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

  /** Persists the current rule order/edits as features.routing and reports the outcome. */
  const handleSave = () => {
    const reportFailure = () => notify.error(__("Couldn't save routing rules."))
    updateSettings.mutate(
      { features: { ...settings.features, routing: toStoredRules(rules) } },
      {
        onSuccess: response =>
          response.status === 'success' ? notify.success(__('Routing rules saved.')) : reportFailure(),
        onError: reportFailure
      }
    )
  }

  /** Throws away unsaved edits and restores the saved rules. */
  const discardChanges = () => setRules(toEditableRules(settings))

  return (
    <Flex vertical gap="middle" style={{ padding: token.paddingLG }}>
      <Flex justify="space-between" align="center" gap="middle" wrap>
        <Title level={4} style={{ margin: 0 }}>
          {__('Routing')}
        </Title>
        <Flex align="center" gap="small" wrap>
          {isDirty && (
            <>
              <Text type="warning">{__('You have unsaved changes')}</Text>
              <Button onClick={discardChanges} disabled={updateSettings.isPending}>
                {__('Discard changes')}
              </Button>
            </>
          )}
          <Button
            type="primary"
            onClick={handleSave}
            loading={updateSettings.isPending}
            disabled={!isDirty}
          >
            {__('Save changes')}
          </Button>
        </Flex>
      </Flex>
      <Flex vertical gap={4}>
        <Text type="secondary">
          {__(
            'Rules are checked top to bottom and the first match wins. If no rule matches, mail uses your primary connection.'
          )}
        </Text>
        <Text type="secondary">
          {__(
            "If a rule's connection fails, Bit SMTP tries your primary connection, then your backups."
          )}
        </Text>
      </Flex>
      {rules.length === 0 ? (
        <EmptyRules onAddRule={addRule} />
      ) : (
        <>
          <Text type="secondary" style={{ fontSize: token.fontSizeSM }}>
            {__('Drag rules to change their order.')}
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
        </>
      )}
      <Modal
        open={guard.isLeavePending}
        title={__('Leave without saving?')}
        okText={__('Leave without saving')}
        okButtonProps={{ danger: true }}
        cancelText={__('Stay on this page')}
        onOk={guard.leave}
        onCancel={guard.stay}
      >
        {__("Your routing rule changes haven't been saved and will be lost.")}
      </Modal>
    </Flex>
  )
}
