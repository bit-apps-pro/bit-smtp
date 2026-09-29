import { type DeliveryStatusKey } from '@common/glossary'
import { type StatusMarks, modePalette } from '@config/themes/palette'

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

/** Brand-tinted neutral ink for aggregate series (totals) so they read as context, not as another category. */
const INK = { light: '#4a5a70', dark: '#a9b8cc' } as const

export type StatusKey = keyof StatusMarks

/** Vermilion for the Failed series: >=3:1 on the surface and >=10 ΔE from the teal Delivered series under protan/deutan. */
const FAILED_SERIES = { light: '#c8481c', dark: '#e8692c' } as const

/** Dash pattern for the Failed series, a shape cue so it never relies on color alone. */
export const FAILED_SERIES_DASH = '6 4'

/** The de-emphasis / "Other" gray, brand-tinted - a residual bucket, never a categorical identity slot; darker in dark mode to stay apart from the teal good mark under deutan. */
const OTHER_GRAY = { light: '#8a95a5', dark: '#6b778a' } as const

/** The "Other" gray for the active mode (>=3:1 on the card surface). */
export function otherGray(isDark: boolean): string {
  return OTHER_GRAY[isDark ? 'dark' : 'light']
}

/** Fixed identity colors for the volume-over-time metrics: neutral total, brand blue, teal delivered, vermilion failed (all-pairs CVD-validated). */
export function volumeSeriesColors(isDark: boolean) {
  const mode = isDark ? 'dark' : 'light'
  return {
    total: INK[mode],
    accepted: (isDark ? CATEGORICAL_DARK : CATEGORICAL_LIGHT)[BLUE],
    delivered: modePalette(isDark).statusMark.good,
    failed: FAILED_SERIES[mode]
  }
}

/** Reserved status color for each delivery-breakdown row; blocked and spam share "serious" by design. */
export function deliveryStatusColor(row: DeliveryStatusKey, isDark: boolean): string {
  const status = modePalette(isDark).statusMark
  switch (row) {
    case 'delivered':
      return status.good
    case 'deferred':
      return status.warning
    case 'bounced':
      return status.critical
    case 'blocked':
    case 'spam':
      return status.serious
    default:
      return otherGray(isDark)
  }
}

/** The single brand hue used for every bar in a nominal top-N ranking (never one hue per bar - see anti-patterns.md). */
export function rankingHue(isDark: boolean): string {
  return (isDark ? CATEGORICAL_DARK : CATEGORICAL_LIGHT)[BLUE]
}
