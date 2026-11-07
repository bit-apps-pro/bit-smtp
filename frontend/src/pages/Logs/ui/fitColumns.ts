import { type LogType } from '@pages/Logs/data/useFetchLogs'
import { type TableColumnsType } from 'antd'

/** Narrowest Subject may get before lower-priority columns are dropped to make room. */
const SUBJECT_MIN_WIDTH = 280

/** Width of the row-selection checkbox column, applied to the table's rowSelection. */
export const SELECTION_COLUMN_WIDTH = 38

/** Column keys in the order they are dropped when the table is too narrow; Subject goes last. */
const DROP_ORDER = ['retry_count', 'to_addr', 'connection', 'subject']

/** Width a column needs; Subject has no fixed width so it reserves its minimum. */
function requiredWidth(column: TableColumnsType<LogType>[number]): number {
  return Number(column.width) || SUBJECT_MIN_WIDTH
}

/** Width the columns need together, including the selection column. */
function totalWidth(cols: TableColumnsType<LogType>): number {
  return cols.reduce((sum, column) => sum + requiredWidth(column), SELECTION_COLUMN_WIDTH)
}

/** Drops lower-priority columns, in DROP_ORDER, until the rest fit in `availableWidth`; an unmeasured (0) width keeps all. */
export default function fitColumns(
  cols: TableColumnsType<LogType>,
  availableWidth: number
): TableColumnsType<LogType> {
  if (!availableWidth) {
    return cols
  }

  return DROP_ORDER.reduce(
    (kept, key) =>
      totalWidth(kept) <= availableWidth ? kept : kept.filter(column => column.key !== key),
    cols
  )
}

/** Horizontal scroll config: keep Subject's minimum while it shows, otherwise scroll the remaining columns in place. */
export function scrollFor(cols: TableColumnsType<LogType>): { x: number | 'max-content' } {
  const hasSubject = cols.some(column => column.key === 'subject')
  return {
    x: hasSubject ? totalWidth(cols) : 'max-content'
  }
}
