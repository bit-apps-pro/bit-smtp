import { type CSSProperties } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import { useTheme } from '@config/themes/theme.provider'
import { formatCompactNumber, formatPercent } from '@pages/Analytics/format'
import { volumeSeriesColors } from '@pages/Analytics/palette'
import { type Overview } from '@pages/Analytics/types'
import { Flex, Typography, theme } from 'antd'
import Sparkline from './Sparkline'
import cls from './StatTiles.module.css'

const { Text } = Typography

type TileVars = CSSProperties & Record<`--tile-${string}`, string>

interface Tile {
  key: string
  label: string
  value: string
  hero?: boolean
  trend?: Array<number>
}

/** Divide safely, treating a zero denominator as a flat 0% rather than NaN/Infinity. */
function safeRate(numerator: number, denominator: number): number {
  return denominator === 0 ? 0 : (numerator / denominator) * 100
}

/** Hero "Total sent" + Accepted/Delivered rate + Failed tiles, each with an optional bucket-level sparkline. */
export default function StatTiles({ overview }: { overview: Overview }) {
  const { token } = theme.useToken()
  const { isDark } = useTheme()
  const colors = volumeSeriesColors(isDark)
  const hasTrend = overview.series.length >= 2

  const tiles: Array<Tile> = [
    {
      key: 'total',
      label: __('Total sent'),
      value: formatCompactNumber(overview.total),
      hero: true,
      trend: hasTrend ? overview.series.map(bucket => bucket.total) : undefined
    },
    {
      key: 'accepted_rate',
      label: __('Accepted rate'),
      value: formatPercent(overview.acceptance.accepted_rate),
      trend: hasTrend
        ? overview.series.map(bucket => safeRate(bucket.accepted, bucket.total))
        : undefined
    },
    {
      key: 'delivered_rate',
      label: __('Delivered rate'),
      value: formatPercent(overview.delivery.delivered_rate),
      trend: hasTrend
        ? overview.series.map(bucket => safeRate(bucket.delivered, bucket.verified_delivery))
        : undefined
    },
    {
      key: 'failed',
      label: __('Failed'),
      value: formatCompactNumber(overview.acceptance.failed),
      trend: hasTrend ? overview.series.map(bucket => bucket.failed) : undefined
    }
  ]

  const vars: TileVars = {
    '--tile-border': token.colorBorderSecondary,
    '--tile-bg': token.colorBgContainer
  }

  return (
    <div className={cls.grid} style={vars}>
      {tiles.map(tile => (
        <Flex key={tile.key} vertical className={cls.tile}>
          <Text type="secondary" style={{ fontSize: token.fontSizeSM }}>
            {tile.label}
          </Text>
          <div className={cls.valueRow}>
            <span
              className={`${cls.value} ${tile.hero ? cls.heroValue : cls.tileValue}`}
              style={{ color: token.colorText }}
            >
              {tile.value}
            </span>
            {tile.trend ? (
              <Sparkline
                values={tile.trend}
                mutedColor={token.colorTextQuaternary}
                accentColor={colors.total}
                surfaceColor={token.colorBgContainer}
              />
            ) : null}
          </div>
        </Flex>
      ))}
    </div>
  )
}
