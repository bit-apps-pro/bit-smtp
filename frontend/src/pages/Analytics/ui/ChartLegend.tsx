import { Flex, Typography, theme } from 'antd'

interface LegendItem {
  value: string
  color: string
}

/**
 * Custom legend: a short line-key swatch beside ink-colored text, never text colored by the series
 * (mark-anatomy rule - color lives on the mark, not the label). Mandatory whenever >=2 series render.
 */
export default function ChartLegend({
  payload
}: {
  payload?: Array<{ value?: string; color?: string }>
}) {
  const { token } = theme.useToken()
  if (!payload || payload.length < 2) return null

  const items: Array<LegendItem> = payload
    .filter((entry): entry is { value: string; color: string } => Boolean(entry.value && entry.color))
    .map(entry => ({ value: entry.value, color: entry.color }))

  return (
    <Flex gap="middle" wrap style={{ paddingTop: 4 }}>
      {items.map(item => (
        <Flex key={item.value} align="center" gap={6}>
          <span
            style={{
              display: 'inline-block',
              width: 14,
              height: 2,
              borderRadius: 1,
              background: item.color
            }}
          />
          <Typography.Text style={{ fontSize: 12, color: token.colorTextSecondary }}>
            {item.value}
          </Typography.Text>
        </Flex>
      ))}
    </Flex>
  )
}
