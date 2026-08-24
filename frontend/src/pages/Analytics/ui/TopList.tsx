import { useState } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import { useTheme } from '@config/themes/theme.provider'
import { formatExactNumber } from '@pages/Analytics/format'
import { type LogsFilter, cleanLogsFilter } from '@pages/Analytics/logsFilter'
import { OTHER_GRAY, rankingHue } from '@pages/Analytics/palette'
import { type DimensionGroup } from '@pages/Analytics/types'
import { Flex, Typography, theme } from 'antd'
import ChartCard from './ChartCard'
import DataTable from './DataTable'
import PointDetailModal, { type PointDetailMetric } from './PointDetailModal'
import clickableRowProps from './clickableRow'

const VISIBLE_LIMIT = 8

interface DisplayRow {
  dimension: string
  total: number
  accepted: number
  failed: number
  delivered: number
  isOther: boolean
}

/** Fold rank 9+ into a single "Other" residual row - never a 9th generated hue (see anti-patterns.md). */
function toDisplayRows(groups: Array<DimensionGroup>): Array<DisplayRow> {
  const visible = groups.slice(0, VISIBLE_LIMIT).map(group => ({ ...group, isOther: false }))
  const overflow = groups.slice(VISIBLE_LIMIT)
  if (overflow.length === 0) return visible

  const other = overflow.reduce(
    (sum, group) => ({
      dimension: __('Other'),
      total: sum.total + group.total,
      accepted: sum.accepted + group.accepted,
      failed: sum.failed + group.failed,
      delivered: sum.delivered + group.delivered,
      isOther: true
    }),
    { dimension: __('Other'), total: 0, accepted: 0, failed: 0, delivered: 0, isOther: true }
  )
  return [...visible, other]
}

/** Which `logs/all` filter key a ranking row's dimension maps to - source plugin or connection id. */
type LogsFilterKey = 'source_plugin' | 'connection_id'

interface TopListProps {
  title: string
  emptyLabel: string
  groups: Array<DimensionGroup>
  /** Which query param a row's dimension becomes when deep-linking to Logs (Top sources vs Top connections). */
  logsFilterKey: LogsFilterKey
  /** Analytics dashboard's selected range ('YYYY-MM-DD'), carried into each row's "View in logs" link. */
  dateFrom: string
  dateTo: string
  /**
   * Maps a raw dimension to a human label for DISPLAY only (e.g. a connection id → its name). The
   * raw dimension still drives the "View in logs" filter and row key. Defaults to identity.
   */
  resolveLabel?: (dimension: string) => string
}

/** Builds the drill-down metric list for one ranking row: its name and all of its total/accepted/delivered/failed counts. */
export function buildTopListRowMetrics(
  row: DisplayRow,
  name: string = row.dimension
): Array<PointDetailMetric> {
  return [
    { label: __('Name'), value: name },
    { label: __('Total'), value: formatExactNumber(row.total) },
    { label: __('Accepted'), value: formatExactNumber(row.accepted) },
    { label: __('Delivered'), value: formatExactNumber(row.delivered) },
    { label: __('Failed'), value: formatExactNumber(row.failed) }
  ]
}

interface DetailState {
  title: string
  metrics: Array<PointDetailMetric>
  logsFilter?: LogsFilter
}

/** No filter for the folded "Other" residual or an unattributed "unknown" dimension - neither can be filtered on. */
function logsFilterForRow(
  row: DisplayRow,
  key: LogsFilterKey,
  dateFrom: string,
  dateTo: string
): LogsFilter | undefined {
  if (row.isOther || row.dimension === 'unknown') return undefined
  return cleanLogsFilter({ [key]: row.dimension, date_from: dateFrom, date_to: dateTo })
}

/**
 * Nominal ranking bar list (top sources / top connections): one series, one hue for every real bar -
 * magnitude already reads from bar length, so per-bar rainbow coloring would double-encode it.
 */
export default function TopList({
  title,
  emptyLabel,
  groups,
  logsFilterKey,
  dateFrom,
  dateTo,
  resolveLabel = (dimension: string) => dimension
}: TopListProps) {
  const { token } = theme.useToken()
  const { isDark } = useTheme()
  const rows = toDisplayRows(groups)
  const max = Math.max(1, ...rows.map(row => row.total))
  const hue = rankingHue(isDark)
  const [detail, setDetail] = useState<DetailState | null>(null)

  const tableView = (
    <DataTable
      rowKey="dimension"
      rows={rows}
      columns={[
        {
          title: __('Name'),
          dataIndex: 'dimension',
          key: 'dimension',
          render: (value: string) => resolveLabel(value)
        },
        { title: __('Total'), dataIndex: 'total', key: 'total' },
        { title: __('Accepted'), dataIndex: 'accepted', key: 'accepted' },
        { title: __('Delivered'), dataIndex: 'delivered', key: 'delivered' },
        { title: __('Failed'), dataIndex: 'failed', key: 'failed' }
      ]}
    />
  )

  if (rows.length === 0) {
    return (
      <ChartCard title={title} tableView={tableView}>
        <Typography.Text type="secondary">{emptyLabel}</Typography.Text>
      </ChartCard>
    )
  }

  return (
    <ChartCard title={title} tableView={tableView}>
      <Flex vertical gap={8}>
        {rows.map(row => {
          // Display label (e.g. connection name) — the raw dimension still drives filter + row key.
          const label = resolveLabel(row.dimension)
          const rowClick = clickableRowProps(() =>
            setDetail({
              title: label,
              metrics: buildTopListRowMetrics(row, label),
              logsFilter: logsFilterForRow(row, logsFilterKey, dateFrom, dateTo)
            })
          )
          return (
            <Flex
              key={row.dimension}
              align="center"
              gap={12}
              role={rowClick.role}
              tabIndex={rowClick.tabIndex}
              style={rowClick.style}
              onClick={rowClick.onClick}
              onKeyDown={rowClick.onKeyDown}
            >
              <Typography.Text
                ellipsis
                title={label}
                style={{ fontSize: 13, width: 160, flexShrink: 0, color: token.colorText }}
              >
                {label}
              </Typography.Text>
              <div style={{ flex: 1, height: 8, background: token.colorFillTertiary, borderRadius: 4 }}>
                <div
                  style={{
                    width: `${(row.total / max) * 100}%`,
                    height: '100%',
                    background: row.isOther ? OTHER_GRAY : hue,
                    // Rounded at the tip, square at the baseline (the bar grows from the left edge).
                    borderRadius: '0 4px 4px 0'
                  }}
                />
              </div>
              <Typography.Text
                style={{ fontSize: 13, width: 56, textAlign: 'right', color: token.colorText }}
              >
                {formatExactNumber(row.total)}
              </Typography.Text>
            </Flex>
          )
        })}
      </Flex>
      <PointDetailModal
        open={detail !== null}
        title={detail?.title ?? ''}
        metrics={detail?.metrics ?? []}
        logsFilter={detail?.logsFilter}
        onClose={() => setDetail(null)}
      />
    </ChartCard>
  )
}
