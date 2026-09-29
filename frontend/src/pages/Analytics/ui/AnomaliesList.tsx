import { type CSSProperties } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import { modePalette } from '@config/themes/palette'
import { useTheme } from '@config/themes/theme.provider'
import { type Direction, describeObservation } from '@pages/Analytics/anomalies'
import { type StatusKey } from '@pages/Analytics/palette'
import { type Anomalies } from '@pages/Analytics/types'
import { Flex, Tag, type TagProps, Typography, theme } from 'antd'
import { AlertTriangle, ArrowDownRight, ArrowUpRight, CheckCircle2, Info } from 'lucide-react'
import cls from './AnomaliesList.module.css'
import ChartCard from './ChartCard'
import { InsufficientHistoryState } from './EmptyStates'

const { Text } = Typography

const SEVERITY_ICON: Record<StatusKey, typeof AlertTriangle> = {
  good: CheckCircle2,
  warning: AlertTriangle,
  serious: AlertTriangle,
  critical: AlertTriangle
}

/** Themed Tag preset per severity, so the pill text meets AA on its tint instead of white-on-hex. */
const SEVERITY_PRESET: Record<Exclude<StatusKey, 'serious'>, string> = {
  good: 'success',
  warning: 'warning',
  critical: 'error'
}

const PILL_STYLE: CSSProperties = { width: 'fit-content' }

/** Tag color props for a severity: an antd status preset, or the palette's serious surface, which antd has no preset for. */
function severityTagProps(
  severity: StatusKey | null,
  isDark: boolean
): Pick<TagProps, 'color' | 'style'> {
  if (severity === null) return { style: PILL_STYLE }
  if (severity !== 'serious') return { color: SEVERITY_PRESET[severity], style: PILL_STYLE }
  const { bg, border, text } = modePalette(isDark).serious
  return { style: { ...PILL_STYLE, background: bg, borderColor: border, color: text } }
}

const SEVERITY_LABEL: Record<StatusKey, string> = {
  good: __('Improved'),
  warning: __('Minor'),
  serious: __('Significant'),
  critical: __('Critical')
}

/** Arrow icon for a described observation's direction; `null` (no icon) when there's no meaningful shift. */
function directionIcon(direction: Direction | null): typeof ArrowUpRight | null {
  if (direction === 'up') return ArrowUpRight
  if (direction === 'down') return ArrowDownRight
  return null
}

type CardVars = CSSProperties & Record<`--card-${string}`, string>

/** Current-vs-prior-period observations as cards: direction arrow + label, severity icon+label (never color alone). */
export default function AnomaliesList({ anomalies }: { anomalies: Anomalies }) {
  const { token } = theme.useToken()
  const { isDark } = useTheme()
  const cardVars: CardVars = { '--card-border': token.colorBorderSecondary }

  if (!anomalies.comparison_coverage.complete) {
    return (
      <ChartCard title={__('Anomalies')} tableView={<InsufficientHistoryState />}>
        <InsufficientHistoryState />
      </ChartCard>
    )
  }

  if (anomalies.observations.length === 0) {
    const empty = <Text type="secondary">{__('No notable changes vs. the prior period.')}</Text>
    return (
      <ChartCard title={__('Anomalies')} tableView={empty}>
        {empty}
      </ChartCard>
    )
  }

  const tableView = (
    <ul>
      {anomalies.observations.map((observation, index) => {
        const described = describeObservation(observation)
        // eslint-disable-next-line react/no-array-index-key
        return <li key={index}>{`${described.label} — ${described.detail}`}</li>
      })}
    </ul>
  )

  return (
    <ChartCard
      title={__('Anomalies')}
      subtitle={__('vs. the equivalent prior period.')}
      tableView={tableView}
    >
      <div className={cls.grid} style={cardVars}>
        {anomalies.observations.map((observation, index) => {
          const described = describeObservation(observation)
          const DirectionIcon = directionIcon(described.direction)
          const SeverityIcon = described.severity ? SEVERITY_ICON[described.severity] : Info
          const severityLabel = described.severity ? SEVERITY_LABEL[described.severity] : __('Info')
          const severityTag = severityTagProps(described.severity, isDark)

          return (
            // eslint-disable-next-line react/no-array-index-key
            <div key={index} className={cls.card}>
              <Flex align="center" gap={8}>
                {DirectionIcon ? (
                  <DirectionIcon size={16} color={token.colorTextSecondary} aria-hidden="true" />
                ) : null}
                <Text strong style={{ fontSize: 13 }}>
                  {described.label}
                </Text>
              </Flex>
              <Text type="secondary" style={{ fontSize: 12 }}>
                {described.detail}
              </Text>
              <Tag
                icon={<SeverityIcon size={12} style={{ marginRight: 2 }} />}
                color={severityTag.color}
                style={severityTag.style}
              >
                {severityLabel}
              </Tag>
            </div>
          )
        })}
      </div>
    </ChartCard>
  )
}
