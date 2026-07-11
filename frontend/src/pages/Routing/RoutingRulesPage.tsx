import { useEffect, useState } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import notify from '@components/Toaster/Toaster'
import useMailSettings from '@pages/Connections/data/useMailSettings'
import useUpdateSettings from '@pages/Connections/data/useUpdateSettings'
import { type MailSettings } from '@pages/Connections/types'
import { type EditableRoutingRule, type RoutingRule } from '@pages/Routing/types'
import { Button, Flex, Spin, Typography } from 'antd'
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

export default function RoutingRulesPage() {
  const { data: settings, isPending } = useMailSettings()
  const updateSettings = useUpdateSettings()
  const [rules, setRules] = useState<EditableRoutingRule[]>([])

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

  const handleSave = () => {
    updateSettings.mutate(
      { features: { ...settings.features, routing: toStoredRules(rules) } },
      { onSuccess: () => notify.success(__('Routing rules saved')) }
    )
  }

  return (
    <Flex vertical gap="middle" style={{ padding: 24 }}>
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
      <Flex vertical gap="middle">
        {rules.map((rule, index) => (
          <RoutingRuleRow
            key={rule.id}
            rule={rule}
            connections={settings.connections}
            onChange={updated => updateRule(index, updated)}
            onRemove={() => removeRule(index)}
          />
        ))}
      </Flex>
      <Button type="dashed" onClick={addRule}>
        {__('Add rule')}
      </Button>
    </Flex>
  )
}
