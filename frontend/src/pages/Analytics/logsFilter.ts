/** Analytics → Logs deep-link filter helpers: builds `logs/all`-shaped query params from a drill-down point. */

export type LogsFilter = Record<string, string>

/** Drops keys with an empty/undefined value so a built filter never carries a blank query param. */
export function cleanLogsFilter(filter: Record<string, string | undefined>): LogsFilter {
  return Object.fromEntries(
    Object.entries(filter).filter((entry): entry is [string, string] => Boolean(entry[1]))
  )
}
