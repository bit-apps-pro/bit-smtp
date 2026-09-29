import { type MailSettings } from '@pages/Connections/types'
import { type RoutingRule } from '@pages/Routing/types'

/** features.routing is untyped JSON from the backend; trust the stored rule shape when it is an array. */
export function readRoutingRules(features: MailSettings['features']): RoutingRule[] {
  return Array.isArray(features.routing) ? (features.routing as RoutingRule[]) : []
}

/** Number of stored routing rules that target each connection id. */
export function countRulesByConnection(settings: MailSettings): Map<string, number> {
  const counts = new Map<string, number>()
  readRoutingRules(settings.features).forEach(rule => {
    counts.set(rule.connectionId, (counts.get(rule.connectionId) ?? 0) + 1)
  })
  return counts
}

/** Projects rules (editable or stored) onto the exact stored shape, dropping client-only ids and unknown keys. */
export function toStoredRules(rules: RoutingRule[]): RoutingRule[] {
  return rules.map(({ connectionId, conditions }) => ({
    connectionId,
    conditions: conditions.map(({ field, operator, value }) => ({ field, operator, value }))
  }))
}

/** True when two rule lists would persist identically. */
export function sameStoredRules(left: RoutingRule[], right: RoutingRule[]): boolean {
  return JSON.stringify(toStoredRules(left)) === JSON.stringify(toStoredRules(right))
}
