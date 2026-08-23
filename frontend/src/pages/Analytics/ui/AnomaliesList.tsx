import { type CSSProperties } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import { describeObservation } from '@pages/Analytics/anomalies'
import { STATUS, type StatusKey } from '@pages/Analytics/palette'
import { type Anomalies } from '@pages/Analytics/types'
import { Flex, Tag, Typography, theme } from 'antd'
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

const SEVERITY_LABEL: Record<StatusKey, string> = {
  good: __('Improved'),
  warning: __('Watch'),
  serious: __('Notable'),
  critical: __('Critical')
}

type CardVars = CSSProperties & Record<`--card-${string}`, string>

/** Current-vs-prior-period observations as cards: direction arrow + label, severity icon+label (never color alone). */
export default function AnomaliesList({ anomalies }: { anomalies: Anomalies }) {
  const { token } = theme.useToken()
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
          const DirectionIcon = described.direction === 'up' ? ArrowUpRight : ArrowDownRight
          const SeverityIcon = described.severity ? SEVERITY_ICON[described.severity] : Info
          const severityColor = described.severity ? STATUS[described.severity] : token.colorTextTertiary
          const severityLabel = described.severity ? SEVERITY_LABEL[described.severity] : __('Info')

          return (
            // eslint-disable-next-line react/no-array-index-key
            <div key={index} className={cls.card}>
              <Flex align="center" gap={8}>
                <DirectionIcon size={16} color={token.colorTextSecondary} aria-hidden="true" />
                <Text strong style={{ fontSize: 13 }}>
                  {described.label}
                </Text>
              </Flex>
              <Text type="secondary" style={{ fontSize: 12 }}>
                {described.detail}
              </Text>
              <Tag
                icon={<SeverityIcon size={12} style={{ marginRight: 2 }} />}
                color={severityColor}
                style={{ width: 'fit-content' }}
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
