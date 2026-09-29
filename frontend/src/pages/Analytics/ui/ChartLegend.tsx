import { type CSSProperties } from 'react'
import { Flex, Typography, theme } from 'antd'

interface LegendItem {
  value: string
  color: string
  dashed: boolean
}

interface LegendEntry {
  value?: string
  color?: string
  payload?: { strokeDasharray?: string | number }
}

/** A 14px line-key swatch, dashed when its series line is dashed so the legend mirrors the plotted shape. */
function swatchStyle(item: LegendItem): CSSProperties {
  const base: CSSProperties = { display: 'inline-block', width: 14, borderRadius: 1 }
  return item.dashed
    ? { ...base, height: 0, borderTop: `2px dashed ${item.color}` }
    : { ...base, height: 2, background: item.color }
}

/** Custom legend: line-key swatch beside ink-colored text, never text colored by the series (mark-anatomy rule); mandatory whenever >=2 series render. */
export default function ChartLegend({ payload }: { payload?: ReadonlyArray<LegendEntry> }) {
  const { token } = theme.useToken()
  if (!payload || payload.length < 2) return null

  const items: Array<LegendItem> = payload
    .filter((entry): entry is LegendEntry & { value: string; color: string } =>
      Boolean(entry.value && entry.color)
    )
    .map(entry => ({
      value: entry.value,
      color: entry.color,
      dashed: Boolean(entry.payload?.strokeDasharray)
    }))

  return (
    <Flex gap="middle" wrap style={{ paddingTop: 4 }}>
      {items.map(item => (
        <Flex key={item.value} align="center" gap={6}>
          <span style={swatchStyle(item)} />
          <Typography.Text style={{ fontSize: 12, color: token.colorTextSecondary }}>
            {item.value}
          </Typography.Text>
        </Flex>
      ))}
    </Flex>
  )
}
