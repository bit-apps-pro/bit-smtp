const COMPACT_NUMBER = new Intl.NumberFormat(undefined, {
  notation: 'compact',
  maximumFractionDigits: 1
})
const EXACT_NUMBER = new Intl.NumberFormat(undefined)

/** Auto-compact a count for a stat-tile value: 1,284 / 12.9K / 4.2M. */
export function formatCompactNumber(value: number): string {
  return COMPACT_NUMBER.format(value)
}

/** Thousands-comma'd count, for table cells and axis ticks that must align. */
export function formatExactNumber(value: number): string {
  return EXACT_NUMBER.format(value)
}

/** A percentage already computed server-side (0-100 scale) to one decimal place. */
export function formatPercent(value: number): string {
  return `${value.toFixed(1)}%`
}

/** Signed percentage-point delta, e.g. "+3.2pp" / "-1.0pp". */
export function formatSignedPoints(value: number): string {
  const sign = value > 0 ? '+' : ''
  return `${sign}${value.toFixed(1)}pp`
}

/** Signed percentage delta, e.g. "+12.0%" / "-4.5%". */
export function formatSignedPercent(value: number): string {
  const sign = value > 0 ? '+' : ''
  return `${sign}${value.toFixed(1)}%`
}
