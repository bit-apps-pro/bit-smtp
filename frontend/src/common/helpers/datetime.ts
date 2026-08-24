/** Format an ISO timestamp for display; empty -> em-dash, unparseable -> the raw value. */
// eslint-disable-next-line import/prefer-default-export -- a shared named helper, imported by name across views
export function formatTimestamp(value?: string | null): string {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString()
}
