import { __ } from '@common/helpers/i18nwrap'
import { formatExactNumber, formatPercent } from '@pages/Analytics/format'
import { type Engagement } from '@pages/Analytics/types'
import { Flex, Tooltip, Typography, theme } from 'antd'
import ChartCard from './ChartCard'
import DataTable from './DataTable'

const { Text } = Typography

interface Stat {
  key: string
  label: string
  value: string
  /** Tooltip surfaced next to the value when the figure carries an honesty caveat worth flagging. */
  caveat?: string
}

/** The automated-fire caveat shared by the opens/clicks tiles - a bare count must never read as human. */
function automatedCaveat(automated: number): string | undefined {
  return automated > 0
    ? __(
        'Includes automated fires (Apple Mail Privacy Protection, image proxies, prefetch bots) that are excluded from the confirmed-human figure.'
      )
    : undefined
}

/** One labelled figure; muted and tooltip-hinted when it carries an honesty caveat (automated / no data). */
function StatTile({
  stat,
  textColor,
  mutedColor
}: {
  stat: Stat
  textColor: string
  mutedColor: string
}) {
  const value = (
    <Text strong style={{ fontSize: 20, color: stat.caveat ? mutedColor : textColor }}>
      {stat.value}
    </Text>
  )
  return (
    <Flex vertical gap={2} style={{ minWidth: 140 }}>
      <Text type="secondary" style={{ fontSize: 12 }}>
        {stat.label}
      </Text>
      {stat.caveat ? <Tooltip title={stat.caveat}>{value}</Tooltip> : value}
    </Flex>
  )
}

/** Engagement card: an honest opens/clicks split (automated flagged, never inflated) plus human rates. */
export default function EngagementMetrics({ engagement }: { engagement: Engagement }) {
  const { token } = theme.useToken()
  const { opens, clicks, open_rate: openRate, click_rate: clickRate } = engagement
  const hasOpenDenominator = openRate.denominator > 0
  const hasClickDenominator = clickRate.denominator > 0

  // Honest headline: total opens, how many were machine-fired, and the confirmed-human remainder.
  const honestOpens = `${__('Opens')}: ${formatExactNumber(opens.total)} (${formatExactNumber(
    opens.automated
  )} ${__('flagged automated — Apple Mail Privacy / image proxies / prefetch')}). ${__(
    'Confirmed human'
  )}: ${formatExactNumber(opens.human)}.`

  const stats: Array<Stat> = [
    {
      key: 'opens',
      label: __('Total opens'),
      value: formatExactNumber(opens.total),
      caveat: automatedCaveat(opens.automated)
    },
    { key: 'human_opens', label: __('Confirmed human opens'), value: formatExactNumber(opens.human) },
    {
      key: 'open_rate',
      label: __('Human open rate'),
      value: hasOpenDenominator ? formatPercent(openRate.rate) : '—',
      caveat: hasOpenDenominator
        ? undefined
        : __('No accepted or delivered emails in this range yet to measure an open rate against.')
    },
    {
      key: 'clicks',
      label: __('Total clicks'),
      value: formatExactNumber(clicks.total),
      caveat: automatedCaveat(clicks.automated)
    }
  ]

  const rows: Array<Stat> = [
    { key: 'opens_total', label: __('Total opens'), value: formatExactNumber(opens.total) },
    { key: 'opens_automated', label: __('Automated opens'), value: formatExactNumber(opens.automated) },
    { key: 'opens_human', label: __('Confirmed human opens'), value: formatExactNumber(opens.human) },
    { key: 'opens_unique', label: __('Unique opened messages'), value: formatExactNumber(opens.unique) },
    {
      key: 'open_rate',
      label: __('Human open rate'),
      value: hasOpenDenominator ? formatPercent(openRate.rate) : '—'
    },
    { key: 'clicks_total', label: __('Total clicks'), value: formatExactNumber(clicks.total) },
    {
      key: 'clicks_automated',
      label: __('Automated clicks'),
      value: formatExactNumber(clicks.automated)
    },
    { key: 'clicks_human', label: __('Confirmed human clicks'), value: formatExactNumber(clicks.human) },
    {
      key: 'clicks_unique',
      label: __('Unique clicked messages'),
      value: formatExactNumber(clicks.unique)
    },
    {
      key: 'click_rate',
      label: __('Human click rate'),
      value: hasClickDenominator ? formatPercent(clickRate.rate) : '—'
    }
  ]

  const tableView = (
    <DataTable
      rowKey="key"
      rows={rows}
      columns={[
        { title: __('Metric'), dataIndex: 'label', key: 'label' },
        { title: __('Value'), dataIndex: 'value', key: 'value' }
      ]}
    />
  )

  return (
    <ChartCard
      title={__('Engagement')}
      subtitle={engagement.engagement_interpretation}
      tableView={tableView}
    >
      <Flex vertical gap={token.padding}>
        <Text type="secondary" style={{ fontSize: token.fontSizeSM }}>
          {honestOpens}
        </Text>
        <Flex gap={token.paddingLG} wrap>
          {stats.map(stat => (
            <StatTile
              key={stat.key}
              stat={stat}
              textColor={token.colorText}
              mutedColor={token.colorTextTertiary}
            />
          ))}
        </Flex>
      </Flex>
    </ChartCard>
  )
}
