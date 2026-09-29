import { type CSSProperties } from 'react'
import { Link } from 'react-router-dom'
import { type GlossaryEntry } from '@common/glossary'
import { __, sprintf } from '@common/helpers/i18nwrap'
import { HelpPopover } from '@components/FieldHelp'
import { useTheme } from '@config/themes/theme.provider'
import { formatCompactNumber, formatPercent } from '@pages/Analytics/format'
import { type LogsFilter, cleanLogsFilter } from '@pages/Analytics/logsFilter'
import { volumeSeriesColors } from '@pages/Analytics/palette'
import { type Overview } from '@pages/Analytics/types'
import { METRICS } from '@pages/Analytics/vocabulary'
import { Flex, Tooltip, Typography, theme } from 'antd'
import Sparkline from './Sparkline'
import cls from './StatTiles.module.css'

const { Text } = Typography

type TileVars = CSSProperties & Record<`--tile-${string}`, string>

interface Tile {
  key: string
  metric: GlossaryEntry
  /** Whether the tile label carries a ⓘ popover explaining the metric. */
  explained?: boolean
  value: string
  hero?: boolean
  trend?: Array<number>
  /** Tooltip shown next to the value when the metric carries a caveat worth surfacing (e.g. no data yet). */
  caveat?: string
  /** Filtered Logs view behind the tile's "View in logs" link, with its accessible name. */
  logsLink?: { filter: LogsFilter; label: string }
}

interface StatTilesProps {
  overview: Overview
  /** Selected range ('YYYY-MM-DD'), carried into each tile's "View in logs" link. */
  dateFrom?: string
  dateTo?: string
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

/** A tile's label, with a ⓘ explanation for metrics that are easy to misread. */
function TileLabel({ tile, fontSize }: { tile: Tile; fontSize: number }) {
  return (
    <Flex align="center" gap={2}>
      <Text type="secondary" style={{ fontSize }}>
        {tile.metric.label}
      </Text>
      {tile.explained ? (
        <HelpPopover
          label={sprintf(__('What %s means'), tile.metric.label)}
          details={{ title: tile.metric.label, body: tile.metric.hint }}
        />
      ) : null}
    </Flex>
  )
}

/** Hero "Total emails" + Sent/Delivered rate + Failed tiles, each with an optional bucket-level sparkline. */
export default function StatTiles({ overview, dateFrom, dateTo }: StatTilesProps) {
  const { token } = theme.useToken()
  const { isDark } = useTheme()
  const colors = volumeSeriesColors(isDark)
  const hasTrend = overview.series.length >= 2
  const hasDeliveryConfirmations = overview.delivery.denominator > 0

  const tiles: Array<Tile> = [
    {
      key: 'total',
      metric: METRICS.total,
      value: formatCompactNumber(overview.total),
      hero: true,
      trend: hasTrend ? overview.series.map(bucket => bucket.total) : undefined
    },
    {
      key: 'sent_rate',
      metric: METRICS.sentRate,
      explained: true,
      value: formatPercent(overview.acceptance.accepted_rate),
      trend: hasTrend
        ? overview.series.map(bucket => safeRate(bucket.accepted, bucket.total))
        : undefined
    },
    {
      key: 'delivered_rate',
      metric: METRICS.deliveredRate,
      explained: true,
      value: hasDeliveryConfirmations ? formatPercent(overview.delivery.delivered_rate) : '—',
      caveat: hasDeliveryConfirmations
        ? undefined
        : __('No delivery reports yet. Turn on delivery tracking for a connection to see this.'),
      trend:
        hasTrend && hasDeliveryConfirmations
          ? overview.series.map(bucket => safeRate(bucket.delivered, bucket.verified_delivery))
          : undefined,
      logsLink: hasDeliveryConfirmations
        ? {
            filter: cleanLogsFilter({
              delivery_status: 'delivered',
              date_from: dateFrom,
              date_to: dateTo
            }),
            label: __('View delivered emails in logs')
          }
        : undefined
    },
    {
      key: 'failed',
      metric: METRICS.failed,
      value: formatCompactNumber(overview.acceptance.failed),
      trend: hasTrend ? overview.series.map(bucket => bucket.failed) : undefined,
      logsLink:
        overview.acceptance.failed > 0
          ? {
              filter: cleanLogsFilter({ status: 'failed', date_from: dateFrom, date_to: dateTo }),
              label: __('View failed emails in logs')
            }
          : undefined
    }
  ]

  const vars: TileVars = {
    '--tile-border': token.colorBorderSecondary,
    '--tile-bg': token.colorBgContainer,
    '--tile-gap': `${token.padding}px`,
    '--tile-padding': `${token.padding}px ${token.paddingLG}px`
  }

  return (
    <div className={cls.grid} style={vars}>
      {tiles.map(tile => (
        <div key={tile.key} className={cls.tile}>
          <TileLabel tile={tile} fontSize={token.fontSizeSM} />
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
          {tile.logsLink ? (
            <Link
              to={`/logs?${new URLSearchParams(tile.logsLink.filter).toString()}`}
              aria-label={tile.logsLink.label}
              style={{ fontSize: token.fontSizeSM }}
            >
              {__('View in logs')}
            </Link>
          ) : null}
        </div>
      ))}
    </div>
  )
}
