import { useState } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import { useTheme } from '@config/themes/theme.provider'
import { formatExactNumber, formatPercent } from '@pages/Analytics/format'
import usePrefersReducedMotion from '@pages/Analytics/hooks/usePrefersReducedMotion'
import { type LogsFilter, cleanLogsFilter } from '@pages/Analytics/logsFilter'
import { volumeSeriesColors } from '@pages/Analytics/palette'
import { type SeriesBucket } from '@pages/Analytics/types'
import { Typography, theme } from 'antd'
import dayjs from 'dayjs'
import {
  Legend,
  Line,
  LineChart,
  type MouseHandlerDataParam,
  ReferenceDot,
  ResponsiveContainer,
  Tooltip
} from 'recharts'
import ChartCard from './ChartCard'
import ChartLegend from './ChartLegend'
import ChartTooltip from './ChartTooltip'
import DataTable from './DataTable'
import PointDetailModal, { type PointDetailMetric } from './PointDetailModal'
import { CHART_MARGIN, ChartGrid, ChartXAxis, ChartYAxis } from './chartConfig'

interface SeriesDef {
  key: 'total' | 'accepted' | 'delivered' | 'failed'
  name: string
  color: string
}

interface VolumeSeriesColors {
  total: string
  accepted: string
  delivered: string
  failed: string
}

interface DetailState {
  title: string
  metrics: Array<PointDetailMetric>
  logsFilter: LogsFilter
}

/** A count-over-count ratio as a formatted percent, or an em dash when the denominator is zero. */
function formatRateOrDash(numerator: number, denominator: number): string {
  return denominator > 0 ? formatPercent((numerator / denominator) * 100) : '—'
}

/**
 * A volume bucket has no `delivery_status` to filter on - scope Logs to the bucket's own day when its
 * `bucket` field parses to one, otherwise fall back to the dashboard's full selected range.
 */
function logsFilterForBucket(
  bucket: SeriesBucket,
  rangeDateFrom: string,
  rangeDateTo: string
): LogsFilter {
  const parsed = dayjs(bucket.bucket)
  if (parsed.isValid()) {
    const day = parsed.format('YYYY-MM-DD')
    return cleanLogsFilter({ date_from: day, date_to: day })
  }
  return cleanLogsFilter({ date_from: rangeDateFrom, date_to: rangeDateTo })
}

/** Builds the full drill-down metric list for one volume bucket: raw counts plus derived accept/delivery rates. */
export function buildVolumeBucketMetrics(
  bucket: SeriesBucket,
  colors: VolumeSeriesColors
): Array<PointDetailMetric> {
  return [
    { label: __('Total'), value: formatExactNumber(bucket.total), color: colors.total },
    { label: __('Accepted'), value: formatExactNumber(bucket.accepted), color: colors.accepted },
    { label: __('Delivered'), value: formatExactNumber(bucket.delivered), color: colors.delivered },
    { label: __('Failed'), value: formatExactNumber(bucket.failed), color: colors.failed },
    { label: __('Accepted rate'), value: formatRateOrDash(bucket.accepted, bucket.total) },
    { label: __('Delivered rate'), value: formatRateOrDash(bucket.delivered, bucket.verified_delivery) }
  ]
}

interface VolumeChartProps {
  series: Array<SeriesBucket>
  /** Analytics dashboard's selected range ('YYYY-MM-DD'), the "View in logs" fallback for buckets with no parseable day. */
  dateFrom: string
  dateTo: string
}

/** Volume-over-time: total/accepted/delivered/failed as 2px lines on one shared axis, per dataviz marks. */
export default function VolumeChart({ series, dateFrom, dateTo }: VolumeChartProps) {
  const { token } = theme.useToken()
  const { isDark } = useTheme()
  const reducedMotion = usePrefersReducedMotion()
  const colors = volumeSeriesColors(isDark)
  const [detail, setDetail] = useState<DetailState | null>(null)

  const seriesDefs: Array<SeriesDef> = [
    { key: 'total', name: __('Total'), color: colors.total },
    { key: 'accepted', name: __('Accepted'), color: colors.accepted },
    { key: 'delivered', name: __('Delivered'), color: colors.delivered },
    { key: 'failed', name: __('Failed'), color: colors.failed }
  ]

  const last = series[series.length - 1]
  const tickInterval = Math.max(0, Math.ceil(series.length / 6) - 1)

  /** Opens the point-detail modal for the bucket under the clicked point, matched by its x-axis label. */
  const handleChartClick = (state: MouseHandlerDataParam) => {
    if (state.activeLabel === undefined) return
    const bucket = series.find(item => item.label === state.activeLabel)
    if (!bucket) return
    setDetail({
      title: bucket.label,
      metrics: buildVolumeBucketMetrics(bucket, colors),
      logsFilter: logsFilterForBucket(bucket, dateFrom, dateTo)
    })
  }

  const tableView = (
    <DataTable
      rowKey="bucket"
      rows={series}
      columns={[
        { title: __('Period'), dataIndex: 'label', key: 'label' },
        { title: __('Total'), dataIndex: 'total', key: 'total' },
        { title: __('Accepted'), dataIndex: 'accepted', key: 'accepted' },
        { title: __('Delivered'), dataIndex: 'delivered', key: 'delivered' },
        { title: __('Failed'), dataIndex: 'failed', key: 'failed' }
      ]}
    />
  )

  return (
    <ChartCard title={__('Volume over time')} tableView={tableView}>
      <ResponsiveContainer width="100%" height={300}>
        <LineChart data={series} margin={CHART_MARGIN} cursor="pointer" onClick={handleChartClick}>
          <ChartGrid />
          <ChartXAxis dataKey="label" interval={tickInterval} />
          <ChartYAxis width={40} />
          <Tooltip content={<ChartTooltip />} cursor={{ stroke: token.colorBorder, strokeWidth: 1 }} />
          <Legend content={<ChartLegend />} />
          {seriesDefs.map(def => (
            <Line
              key={def.key}
              type="monotone"
              dataKey={def.key}
              name={def.name}
              stroke={def.color}
              strokeWidth={2}
              strokeLinecap="round"
              strokeLinejoin="round"
              dot={false}
              activeDot={{ r: 4, strokeWidth: 2, stroke: token.colorBgContainer }}
              isAnimationActive={!reducedMotion}
            />
          ))}
          {last
            ? seriesDefs.map(def => (
                <ReferenceDot
                  key={def.key}
                  x={last.label}
                  y={last[def.key]}
                  r={4}
                  fill={def.color}
                  stroke={token.colorBgContainer}
                  strokeWidth={2}
                />
              ))
            : null}
        </LineChart>
      </ResponsiveContainer>
      {series.length === 0 ? (
        <Typography.Text type="secondary">{__('No bucketed data for this range.')}</Typography.Text>
      ) : null}
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
