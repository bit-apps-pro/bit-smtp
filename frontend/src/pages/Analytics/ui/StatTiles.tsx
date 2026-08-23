import { type CSSProperties } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import { useTheme } from '@config/themes/theme.provider'
import { formatCompactNumber, formatPercent } from '@pages/Analytics/format'
import { volumeSeriesColors } from '@pages/Analytics/palette'
import { type Overview } from '@pages/Analytics/types'
import { Flex, Tooltip, Typography, theme } from 'antd'
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
  /** Tooltip shown next to the value when the metric carries a caveat worth surfacing (e.g. no data yet). */
  caveat?: string
}

/** Divide safely, treating a zero denominator as a flat 0% rather than NaN/Infinity. */
function safeRate(numerator: number, denominator: number): number {
  return denominator === 0 ? 0 : (numerator / denominator) * 100
}

/** A tile's headline figure - muted and tooltip-hinted when the tile carries a caveat (e.g. no data yet). */
function TileValue({
  tile,
  textColor,
  mutedColor
}: {
  tile: Tile
  textColor: string
  mutedColor: string
}) {
  const value = (
    <span
      className={`${cls.value} ${tile.hero ? cls.heroValue : cls.tileValue}`}
      style={{ color: tile.caveat ? mutedColor : textColor }}
    >
      {tile.value}
    </span>
  )
  return tile.caveat ? <Tooltip title={tile.caveat}>{value}</Tooltip> : value
}

/** Hero "Total sent" + Accepted/Delivered rate + Failed tiles, each with an optional bucket-level sparkline. */
export default function StatTiles({ overview }: { overview: Overview }) {
  const { token } = theme.useToken()
  const { isDark } = useTheme()
  const colors = volumeSeriesColors(isDark)
  const hasTrend = overview.series.length >= 2
  // `delivery.denominator` is the webhook-confirmed count - 0 means no confirmations yet, not a 0% rate.
  const hasDeliveryConfirmations = overview.delivery.denominator > 0

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
      value: hasDeliveryConfirmations ? formatPercent(overview.delivery.delivered_rate) : '—',
      caveat: hasDeliveryConfirmations
        ? undefined
        : __(
            'No delivery confirmations yet - this metric only counts sends with a webhook-confirmed status.'
          ),
      trend:
        hasTrend && hasDeliveryConfirmations
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
            <TileValue tile={tile} textColor={token.colorText} mutedColor={token.colorTextTertiary} />
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
