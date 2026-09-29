import { type DeliveryStatusKey } from '@common/glossary'

/** Dataviz chart palette: brand-anchored, CVD-separated categorical hues in a fixed never-reassigned order, plus the dataviz skill's reserved status scale. */
export const CATEGORICAL_LIGHT = [
  '#4f46e5',
  '#1baf7a',
  '#eda100',
  '#e34948',
  '#7c3aed',
  '#008300',
  '#e87ba4',
  '#eb6834'
] as const

export const CATEGORICAL_DARK = [
  '#4f46e5',
  '#199e70',
  '#c98500',
  '#e66767',
  '#7c3aed',
  '#008300',
  '#d55181',
  '#d95926'
] as const

/** Reserved status scale - fixed meaning, never themed, always paired with an icon + label. */
export const STATUS = {
  good: '#0ca30c',
  warning: '#fab219',
  serious: '#ec835a',
  critical: '#d03b3b'
} as const

export type StatusKey = keyof typeof STATUS

/** The de-emphasis / "Other" gray - a residual bucket, never a categorical identity slot (same in both modes). */
export const OTHER_GRAY = '#898781'

/** Fixed identity colors for the volume-over-time metrics (see report for the assignment rationale). */
export function volumeSeriesColors(isDark: boolean) {
  const c = isDark ? CATEGORICAL_DARK : CATEGORICAL_LIGHT
  return {
    total: c[0],
    accepted: c[1],
    delivered: c[4],
    failed: c[3]
  }
}

/** Reserved status color for each delivery-breakdown row; blocked and spam share "serious" by design. */
export function deliveryStatusColor(row: DeliveryStatusKey): string {
  switch (row) {
    case 'delivered':
      return STATUS.good
    case 'deferred':
      return STATUS.warning
    case 'bounced':
      return STATUS.critical
    case 'blocked':
    case 'spam':
      return STATUS.serious
    default:
      return OTHER_GRAY
  }
}

/** The single hue used for every bar in a nominal top-N ranking (never one hue per bar - see anti-patterns.md). */
export function rankingHue(isDark: boolean): string {
  return (isDark ? CATEGORICAL_DARK : CATEGORICAL_LIGHT)[0]
}
