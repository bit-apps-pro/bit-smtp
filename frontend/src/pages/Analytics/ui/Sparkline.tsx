interface SparklineProps {
  values: Array<number>
  mutedColor: string
  accentColor: string
  surfaceColor: string
  width?: number
  height?: number
}

/** Hand-rolled 12-point trend line for a stat tile: de-emphasis hue throughout, accent dot on the current point. */
export default function Sparkline({
  values,
  mutedColor,
  accentColor,
  surfaceColor,
  width = 96,
  height = 28
}: SparklineProps) {
  if (values.length < 2) return null

  const padding = 4
  const min = Math.min(...values)
  const max = Math.max(...values)
  const span = max - min || 1
  const stepX = (width - padding * 2) / (values.length - 1)

  const points = values.map((value, index) => {
    const x = padding + index * stepX
    const y = padding + (1 - (value - min) / span) * (height - padding * 2)
    return [x, y] as const
  })
  const path = points
    .map(([x, y], index) => `${index === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`)
    .join(' ')
  const [lastX, lastY] = points[points.length - 1]

  return (
    <svg width={width} height={height} role="img" aria-hidden="true" focusable="false">
      <path
        d={path}
        fill="none"
        stroke={mutedColor}
        strokeWidth={2}
        strokeLinecap="round"
        strokeLinejoin="round"
      />
      <circle cx={lastX} cy={lastY} r={4} fill={accentColor} stroke={surfaceColor} strokeWidth={2} />
    </svg>
  )
}
