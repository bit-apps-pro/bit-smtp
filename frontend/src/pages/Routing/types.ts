/** A runtime-detected wp_mail sender plugin, offered in the "Source plugin" condition picker. */
export interface MailSource {
  value: string
  label: string
}

/** Source picker options: plugins already seen sending mail, then other installed plugins. */
export interface MailSourceCatalog {
  seen: MailSource[]
  installed: MailSource[]
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

/** UI-only condition with client-generated id for stable list keys; id is stripped before persisting to backend. */
export interface EditableRoutingCondition extends RoutingCondition {
  id: string
}

export interface EditableRoutingRule extends RoutingRule {
  id: string
  conditions: EditableRoutingCondition[]
}
