import { __ } from '@common/helpers/i18nwrap'
import { useTheme } from '@config/themes/theme.provider'
import { formatExactNumber } from '@pages/Analytics/format'
import { OTHER_GRAY, rankingHue } from '@pages/Analytics/palette'
import { type DimensionGroup } from '@pages/Analytics/types'
import { Flex, Typography, theme } from 'antd'
import ChartCard from './ChartCard'
import DataTable from './DataTable'

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

interface TopListProps {
  title: string
  emptyLabel: string
  groups: Array<DimensionGroup>
}

/**
 * Nominal ranking bar list (top sources / top connections): one series, one hue for every real bar -
 * magnitude already reads from bar length, so per-bar rainbow coloring would double-encode it.
 */
export default function TopList({ title, emptyLabel, groups }: TopListProps) {
  const { token } = theme.useToken()
  const { isDark } = useTheme()
  const rows = toDisplayRows(groups)
  const max = Math.max(1, ...rows.map(row => row.total))
  const hue = rankingHue(isDark)

  const tableView = (
    <DataTable
      rowKey="dimension"
      rows={rows}
      columns={[
        { title: __('Name'), dataIndex: 'dimension', key: 'dimension' },
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
        {rows.map(row => (
          <Flex key={row.dimension} align="center" gap={12}>
            <Typography.Text
              ellipsis
              title={row.dimension}
              style={{ fontSize: 13, width: 160, flexShrink: 0, color: token.colorText }}
            >
              {row.dimension}
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
        ))}
      </Flex>
    </ChartCard>
  )
}
