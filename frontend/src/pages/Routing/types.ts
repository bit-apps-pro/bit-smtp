// A runtime-detected wp_mail sender plugin, offered in the "Source plugin" condition picker.
export interface MailSource {
  value: string
  label: string
}

export type RoutingField = 'recipient' | 'from' | 'subject' | 'source_plugin'
export type RoutingOperator = 'equals' | 'contains' | 'domain' | 'matches'

export interface RoutingCondition {
  field: RoutingField
  operator: RoutingOperator
  value: string
}

export interface RoutingRule {
  connectionId: string
  conditions: RoutingCondition[]
}

// UI-only id for stable list keys; generated client-side and stripped before persisting.
export interface EditableRoutingCondition extends RoutingCondition {
  id: string
}

export interface EditableRoutingRule extends RoutingRule {
  id: string
  conditions: EditableRoutingCondition[]
}
