import { useState } from 'react'
import { type DeliveryStatusKey, deliveryStatusEntry } from '@common/glossary'
import { __ } from '@common/helpers/i18nwrap'
import { useTheme } from '@config/themes/theme.provider'
import { formatExactNumber, formatPercent } from '@pages/Analytics/format'
import { type LogsFilter, cleanLogsFilter } from '@pages/Analytics/logsFilter'
import { deliveryStatusColor } from '@pages/Analytics/palette'
import { type DeliveryStats } from '@pages/Analytics/types'
import { glossaryLegendDetails } from '@pages/Logs/ui/GlossaryLegend'
import { Flex, Typography, theme } from 'antd'
import ChartCard from './ChartCard'
import DataTable from './DataTable'
import PointDetailModal, { type PointDetailMetric } from './PointDetailModal'
import clickableRowProps from './clickableRow'

type Row = Extract<
  DeliveryStatusKey,
  'delivered' | 'deferred' | 'bounced' | 'blocked' | 'spam' | 'accepted' | 'pending'
>

/** Breakdown rows in display order; labels and hints come from the shared delivery glossary. */
const ROWS: ReadonlyArray<Row> = [
  'delivered',
  'deferred',
  'bounced',
  'blocked',
  'spam',
  'accepted',
  'pending'
]

const LEGEND = glossaryLegendDetails(
  __('Delivery statuses'),
  ROWS.map(row => deliveryStatusEntry(row))
)

interface BreakdownRow {
  key: Row
  label: string
  value: number
  color: string
}

/** `delivery.delayed` is the API field name for what the product calls "Deferred" everywhere else. */
function rowsFrom(delivery: DeliveryStats, isDark: boolean): Array<BreakdownRow> {
  const values: Record<Row, number> = {
    delivered: delivery.delivered,
    deferred: delivery.delayed,
    bounced: delivery.bounced,
    blocked: delivery.blocked,
    spam: delivery.spam,
    accepted: delivery.accepted,
    pending: delivery.pending
  }
  return ROWS.map(key => ({
    key,
    label: deliveryStatusEntry(key).label,
    value: values[key],
    color: deliveryStatusColor(key, isDark)
  }))
}

/** Builds the drill-down metric list for one breakdown row: status, count, and its share of confirmed outcomes. */
export function buildDeliveryRowMetrics(
  row: BreakdownRow,
  denominator: number
): Array<PointDetailMetric> {
  return [
    { label: __('Status'), value: row.label, color: row.color },
    { label: __('Count'), value: formatExactNumber(row.value) },
    {
      label: __('Share of confirmed outcomes'),
      value: denominator > 0 ? formatPercent((row.value / denominator) * 100) : '—'
    }
  ]
}

interface DetailState {
  title: string
  metrics: Array<PointDetailMetric>
  logsFilter: LogsFilter
}

interface DeliveryBreakdownProps {
  delivery: DeliveryStats
  /** Analytics dashboard's selected range ('YYYY-MM-DD'), carried into each row's "View in logs" link. */
  dateFrom: string
  dateTo: string
}

/** Horizontal bars in reserved per-mode status colors, each paired with a dot + label so identity never rests on color alone. */
export default function DeliveryBreakdown({ delivery, dateFrom, dateTo }: DeliveryBreakdownProps) {
  const { token } = theme.useToken()
  const { isDark } = useTheme()
  const rows = rowsFrom(delivery, isDark)
  const max = Math.max(1, ...rows.map(row => row.value))
  const [detail, setDetail] = useState<DetailState | null>(null)

  const tableView = (
    <DataTable
      rowKey="key"
      rows={rows}
      columns={[
        { title: __('Status'), dataIndex: 'label', key: 'label' },
        { title: __('Count'), dataIndex: 'value', key: 'value' }
      ]}
    />
  )

  return (
    <ChartCard
      title={__('Delivery breakdown')}
      subtitle={__('What happened after sending, for connections that report delivery.')}
      help={LEGEND}
      tableView={tableView}
    >
      <Flex vertical gap={10}>
        {rows.map(row => {
          const rowClick = clickableRowProps(() =>
            setDetail({
              title: row.label,
              metrics: buildDeliveryRowMetrics(row, delivery.denominator),
              logsFilter: cleanLogsFilter({
                delivery_status: row.key,
                date_from: dateFrom,
                date_to: dateTo
              })
            })
          )
          return (
            <Flex
              key={row.key}
              align="center"
              gap={12}
              role={rowClick.role}
              tabIndex={rowClick.tabIndex}
              style={rowClick.style}
              onClick={rowClick.onClick}
              onKeyDown={rowClick.onKeyDown}
            >
              <Flex align="center" gap={6} style={{ width: 200, flexShrink: 0 }}>
                <span
                  aria-hidden="true"
                  style={{ width: 8, height: 8, borderRadius: 4, background: row.color, flexShrink: 0 }}
                />
                <Typography.Text style={{ fontSize: 13, color: token.colorText }}>
                  {row.label}
                </Typography.Text>
              </Flex>
              <div style={{ flex: 1, height: 8, background: token.colorFillTertiary, borderRadius: 4 }}>
                <div
                  style={{
                    width: `${(row.value / max) * 100}%`,
                    height: '100%',
                    background: row.color,
                    borderRadius: '0 4px 4px 0',
                    transition: 'width 200ms ease'
                  }}
                />
              </div>
              <Typography.Text
                style={{ fontSize: 13, width: 56, textAlign: 'right', color: token.colorText }}
              >
                {formatExactNumber(row.value)}
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
