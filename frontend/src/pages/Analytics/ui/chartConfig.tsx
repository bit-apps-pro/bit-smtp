/** Shared recharts scaffolding (margin/grid/axis chrome) so every cartesian chart in the dashboard reads as one system. */
import { theme } from 'antd'
import { CartesianGrid, XAxis, YAxis } from 'recharts'

const AXIS_TICK_STYLE = { fontSize: 11 }

/** Fixed chart margin shared by every cartesian chart, leaving room for the Y-axis width and legend. */
export const CHART_MARGIN = { top: 8, right: 16, left: 0, bottom: 0 } as const

/** Borderless gridlines - the shared background chrome for every cartesian chart. */
export function ChartGrid() {
  const { token } = theme.useToken()
  return <CartesianGrid vertical={false} stroke={token.colorBorderSecondary} />
}

interface ChartXAxisProps {
  dataKey: string
  interval?: number
}

/** Shared X-axis chrome: no tick marks, a solid axis line, muted tick labels. */
export function ChartXAxis({ dataKey, interval }: ChartXAxisProps) {
  const { token } = theme.useToken()
  return (
    <XAxis
      dataKey={dataKey}
      interval={interval}
      tickLine={false}
      axisLine={{ stroke: token.colorBorder }}
      tick={{ ...AXIS_TICK_STYLE, fill: token.colorTextTertiary }}
    />
  )
}

interface ChartYAxisProps {
  width: number
}

/** Shared Y-axis chrome: no tick marks or axis line, muted tick labels, integer ticks only. */
export function ChartYAxis({ width }: ChartYAxisProps) {
  const { token } = theme.useToken()
  return (
    <YAxis
      width={width}
      tickLine={false}
      axisLine={false}
      tick={{ ...AXIS_TICK_STYLE, fill: token.colorTextTertiary }}
      allowDecimals={false}
    />
  )
}
