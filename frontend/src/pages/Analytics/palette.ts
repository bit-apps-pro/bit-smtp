/**
 * Dataviz chart palette for the analytics dashboard.
 *
 * Categorical hues are brand-anchored (indigo/violet, the app's colorPrimary/colorInfo tokens)
 * extended to 8 slots, in the fixed order that maximizes the worst-case adjacent CVD separation
 * (see /home/ar2/Documents/llm-specs/bit-smtp/sdd/2026-08-24-settings-polish-analytics/task2b-report.md
 * for the brute-force search + `validate_palette.js` output). Order is never cycled or reassigned
 * per render - each chart hands its series slots 1..N in this sequence.
 *
 * Status hues are the dataviz skill's reserved, fixed scale (never themed, never reused as identity).
 * Chart chrome (ink, gridlines, surface) intentionally rides the app's own antd theme tokens instead
 * of a parallel palette, via `theme.useToken()` in each chart - see AGENTS.md conventions.
 */

export const CATEGORICAL_LIGHT = [
  '#4f46e5', // 1 indigo (brand primary)
  '#1baf7a', // 2 aqua
  '#eda100', // 3 yellow
  '#e34948', // 4 red
  '#7c3aed', // 5 violet (brand info)
  '#008300', // 6 green
  '#e87ba4', // 7 magenta
  '#eb6834' // 8 orange
] as const

export const CATEGORICAL_DARK = [
  '#4f46e5', // 1 indigo (brand primary)
  '#199e70', // 2 aqua
  '#c98500', // 3 yellow
  '#e66767', // 4 red
  '#7c3aed', // 5 violet (brand info)
  '#008300', // 6 green
  '#d55181', // 7 magenta
  '#d95926' // 8 orange
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
export function deliveryStatusColor(
  row: 'delivered' | 'deferred' | 'bounced' | 'blocked' | 'spam' | 'accepted' | 'pending'
): string {
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
