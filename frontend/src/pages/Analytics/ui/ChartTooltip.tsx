import { formatExactNumber } from '@pages/Analytics/format'
import { Flex, Typography, theme } from 'antd'

interface TooltipEntry {
  name?: string
  value?: number | string
  color?: string
}

interface ChartTooltipProps {
  active?: boolean
  label?: string
  payload?: Array<TooltipEntry>
}

/** Custom crosshair tooltip: one row per series, value leads (bold, high-contrast) and the name follows. */
export default function ChartTooltip({ active, label, payload }: ChartTooltipProps) {
  const { token } = theme.useToken()
  if (!active || !payload?.length) return null

  return (
    <div
      style={{
        background: token.colorBgElevated,
        border: `1px solid ${token.colorBorderSecondary}`,
        borderRadius: token.borderRadius,
        padding: '8px 12px',
        boxShadow: token.boxShadowSecondary
      }}
    >
      <Typography.Text style={{ fontSize: 12, color: token.colorTextTertiary }}>{label}</Typography.Text>
      <Flex vertical gap={2} style={{ marginTop: 4 }}>
        {payload.map(entry => (
          <Flex key={entry.name} align="center" gap={6}>
            <span
              style={{
                display: 'inline-block',
                width: 10,
                height: 2,
                borderRadius: 1,
                background: entry.color
              }}
            />
            <Typography.Text strong style={{ fontSize: 13, color: token.colorText }}>
              {typeof entry.value === 'number' ? formatExactNumber(entry.value) : entry.value}
            </Typography.Text>
            <Typography.Text style={{ fontSize: 12, color: token.colorTextSecondary }}>
              {entry.name}
            </Typography.Text>
          </Flex>
        ))}
      </Flex>
    </div>
  )
}
