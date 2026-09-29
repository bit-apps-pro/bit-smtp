import { type DeliveryStatusKey } from '@common/glossary'
import { SEMANTIC } from '@config/themes/palette'

/** Dataviz categorical palette opening on the logo-blue hue; the order is the CVD-safety mechanism (validated adjacent-pair ΔE ≥ 8 in both modes), never reassigned. */
export const CATEGORICAL_LIGHT = [
  '#1d7ee0',
  '#eb6834',
  '#1baf7a',
  '#eda100',
  '#e87ba4',
  '#008300',
  '#4a3aa7',
  '#e34948'
] as const

export const CATEGORICAL_DARK = [
  '#3b93ea',
  '#d95926',
  '#199e70',
  '#c98500',
  '#d55181',
  '#008300',
  '#9085e9',
  '#e66767'
] as const

const BLUE = 0
const AQUA = 2
const RED = 7

/** Brand-tinted neutral ink for aggregate series (totals) so they read as context, not as another category. */
const INK = { light: '#4a5a70', dark: '#a9b8cc' } as const

/** Reserved status scale sharing the theme's semantic seeds - fixed meaning, always paired with an icon + label. */
export const STATUS = {
  good: SEMANTIC.success,
  warning: SEMANTIC.warning,
  serious: SEMANTIC.serious,
  critical: SEMANTIC.error
} as const

export type StatusKey = keyof typeof STATUS

/** The de-emphasis / "Other" gray, brand-tinted - a residual bucket, never a categorical identity slot (same in both modes). */
export const OTHER_GRAY = '#8a95a5'

/** Fixed identity colors for the volume-over-time metrics: neutral total, then blue/aqua/red (all-pairs CVD-validated trio). */
export function volumeSeriesColors(isDark: boolean) {
  const c = isDark ? CATEGORICAL_DARK : CATEGORICAL_LIGHT
  return {
    total: isDark ? INK.dark : INK.light,
    accepted: c[BLUE],
    delivered: c[AQUA],
    failed: c[RED]
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

/** The single brand hue used for every bar in a nominal top-N ranking (never one hue per bar - see anti-patterns.md). */
export function rankingHue(isDark: boolean): string {
  return (isDark ? CATEGORICAL_DARK : CATEGORICAL_LIGHT)[BLUE]
}
