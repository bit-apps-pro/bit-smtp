import { __ } from '@common/helpers/i18nwrap'
import { useTheme } from '@config/themes/theme.provider'
import usePrefersReducedMotion from '@pages/Analytics/hooks/usePrefersReducedMotion'
import { rankingHue } from '@pages/Analytics/palette'
import { type BusyTimeSlot } from '@pages/Analytics/types'
import { Typography, theme } from 'antd'
import { Bar, BarChart, ResponsiveContainer, Tooltip } from 'recharts'
import ChartCard from './ChartCard'
import ChartTooltip from './ChartTooltip'
import DataTable from './DataTable'
import { CHART_MARGIN, ChartGrid, ChartXAxis, ChartYAxis } from './chartConfig'

/** One accent hue, uniform fill - a single-series magnitude-by-hour chart needs no per-bar identity color. */
export default function BusiestHours({ hours }: { hours: Array<BusyTimeSlot> }) {
  const { token } = theme.useToken()
  const { isDark } = useTheme()
  const reducedMotion = usePrefersReducedMotion()
  const hue = rankingHue(isDark)
  // The backend returns the top-N busiest hours ranked by volume; re-sort chronologically so the
  // bar row reads as a daily rhythm instead of a jumbled rank list.
  const chronological = [...hours].sort((a, b) => a.hour - b.hour)

  const tableView = (
    <DataTable
      rowKey="hour"
      rows={chronological}
      columns={[
        { title: __('Hour'), dataIndex: 'label', key: 'label' },
        { title: __('Sends'), dataIndex: 'total', key: 'total' }
      ]}
    />
  )

  if (chronological.length === 0) {
    return (
      <ChartCard title={__('Busiest hours')} tableView={tableView}>
        <Typography.Text type="secondary">{__('No hourly activity in this range.')}</Typography.Text>
      </ChartCard>
    )
  }

  return (
    <ChartCard title={__('Busiest hours')} subtitle={__('Local site time.')} tableView={tableView}>
      <ResponsiveContainer width="100%" height={220}>
        <BarChart data={chronological} margin={CHART_MARGIN}>
          <ChartGrid />
          <ChartXAxis dataKey="label" />
          <ChartYAxis width={36} />
          <Tooltip content={<ChartTooltip />} cursor={{ fill: token.colorFillTertiary }} />
          <Bar
            dataKey="total"
            name={__('Sends')}
            fill={hue}
            radius={[4, 4, 0, 0]}
            maxBarSize={24}
            isAnimationActive={!reducedMotion}
          />
        </BarChart>
      </ResponsiveContainer>
    </ChartCard>
  )
}
