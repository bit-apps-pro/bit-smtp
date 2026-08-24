import { useState } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import { formatExactNumber, formatPercent } from '@pages/Analytics/format'
import { type LogsFilter, cleanLogsFilter } from '@pages/Analytics/logsFilter'
import { deliveryStatusColor } from '@pages/Analytics/palette'
import { type DeliveryStats } from '@pages/Analytics/types'
import { Flex, Typography, theme } from 'antd'
import ChartCard from './ChartCard'
import DataTable from './DataTable'
import PointDetailModal, { type PointDetailMetric } from './PointDetailModal'
import clickableRowProps from './clickableRow'

type Row = 'delivered' | 'deferred' | 'bounced' | 'blocked' | 'spam' | 'accepted' | 'pending'

const ROW_LABELS: Record<Row, string> = {
  delivered: __('Delivered'),
  deferred: __('Deferred'),
  bounced: __('Bounced'),
  blocked: __('Blocked'),
  spam: __('Spam'),
  accepted: __('Accepted (pre-delivery)'),
  pending: __('Pending')
}

interface BreakdownRow {
  key: Row
  label: string
  value: number
  color: string
}

/** `delivery.delayed` is the API field name for what the product calls "Deferred" everywhere else. */
function rowsFrom(delivery: DeliveryStats): Array<BreakdownRow> {
  const values: Record<Row, number> = {
    delivered: delivery.delivered,
    deferred: delivery.delayed,
    bounced: delivery.bounced,
    blocked: delivery.blocked,
    spam: delivery.spam,
    accepted: delivery.accepted,
    pending: delivery.pending
  }
  return (Object.keys(ROW_LABELS) as Array<Row>).map(key => ({
    key,
    label: ROW_LABELS[key],
    value: values[key],
    color: deliveryStatusColor(key)
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

/** Horizontal bars, reserved status colors, icon/dot + label mitigate the sub-3:1 warning/serious hues. */
export default function DeliveryBreakdown({ delivery, dateFrom, dateTo }: DeliveryBreakdownProps) {
  const { token } = theme.useToken()
  const rows = rowsFrom(delivery)
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
      subtitle={__('Verified delivery outcomes for sends with a webhook-confirmed status.')}
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
                    // Rounded at the tip, square at the baseline (the bar grows from the left edge).
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
