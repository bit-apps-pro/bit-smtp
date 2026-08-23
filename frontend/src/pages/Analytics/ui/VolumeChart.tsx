import { __ } from '@common/helpers/i18nwrap'
import { useTheme } from '@config/themes/theme.provider'
import usePrefersReducedMotion from '@pages/Analytics/hooks/usePrefersReducedMotion'
import { volumeSeriesColors } from '@pages/Analytics/palette'
import { type SeriesBucket } from '@pages/Analytics/types'
import { Typography, theme } from 'antd'
import { Legend, Line, LineChart, ReferenceDot, ResponsiveContainer, Tooltip } from 'recharts'
import ChartCard from './ChartCard'
import ChartLegend from './ChartLegend'
import ChartTooltip from './ChartTooltip'
import DataTable from './DataTable'
import { CHART_MARGIN, ChartGrid, ChartXAxis, ChartYAxis } from './chartConfig'

interface SeriesDef {
  key: 'total' | 'accepted' | 'delivered' | 'failed'
  name: string
  color: string
}

/** Volume-over-time: total/accepted/delivered/failed as 2px lines on one shared axis, per dataviz marks. */
export default function VolumeChart({ series }: { series: Array<SeriesBucket> }) {
  const { token } = theme.useToken()
  const { isDark } = useTheme()
  const reducedMotion = usePrefersReducedMotion()
  const colors = volumeSeriesColors(isDark)

  const seriesDefs: Array<SeriesDef> = [
    { key: 'total', name: __('Total'), color: colors.total },
    { key: 'accepted', name: __('Accepted'), color: colors.accepted },
    { key: 'delivered', name: __('Delivered'), color: colors.delivered },
    { key: 'failed', name: __('Failed'), color: colors.failed }
  ]

  const last = series[series.length - 1]
  const tickInterval = Math.max(0, Math.ceil(series.length / 6) - 1)

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
        <LineChart data={series} margin={CHART_MARGIN}>
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
    </ChartCard>
  )
}
