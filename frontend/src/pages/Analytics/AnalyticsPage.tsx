import { type CSSProperties, useMemo, useState } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import AnalyticsFilters, { type BucketChoice } from '@pages/Analytics/AnalyticsFilters'
import {
  type AnalyticsQueryState,
  analyticsQueryState,
  useAnomalies,
  useOverview
} from '@pages/Analytics/data/useAnalytics'
import { type AnalyticsRangeParams, type Anomalies } from '@pages/Analytics/types'
import AnomaliesList from '@pages/Analytics/ui/AnomaliesList'
import BusiestHours from '@pages/Analytics/ui/BusiestHours'
import ChartCard from '@pages/Analytics/ui/ChartCard'
import DeliveryBreakdown from '@pages/Analytics/ui/DeliveryBreakdown'
import {
  AnalyticsErrorState,
  AnalyticsSkeleton,
  LoggingOffState,
  NoDataState
} from '@pages/Analytics/ui/EmptyStates'
import StatTiles from '@pages/Analytics/ui/StatTiles'
import TopList from '@pages/Analytics/ui/TopList'
import VolumeChart from '@pages/Analytics/ui/VolumeChart'
import useMailSettings from '@pages/Connections/data/useMailSettings'
import usePreferences from '@pages/Settings/data/usePreferences'
import { Flex, Spin, Typography, theme } from 'antd'
import dayjs, { type Dayjs } from 'dayjs'
import cls from './AnalyticsPage.module.css'

const { Title } = Typography

const MIN_RETENTION_DAYS = 1
const MAX_RETENTION_DAYS = 200
const DEFAULT_RETENTION_DAYS = 30

type PageVars = CSSProperties & Record<`--page-${string}`, string>

/** Clamp a preferences-sourced retention value to the same [1,200]/30-default bound the backend enforces. */
function clampRetentionDays(logRetentionDays: number | undefined): number {
  return Math.max(
    MIN_RETENTION_DAYS,
    Math.min(MAX_RETENTION_DAYS, logRetentionDays ?? DEFAULT_RETENTION_DAYS)
  )
}

/** Build the request params for all three endpoints from the filter row's selected range/bucket. */
function toParams(range: [Dayjs, Dayjs], bucket: BucketChoice): AnalyticsRangeParams {
  return {
    start: range[0].startOf('day').toISOString(),
    end: range[1].endOf('day').toISOString(),
    bucket: bucket === 'auto' ? undefined : bucket
  }
}

/** A supplementary panel's placeholder while its own query is still loading, independent of overview's gate. */
function PendingPanel({ title }: { title: string }) {
  return (
    <ChartCard title={title} tableView={<Spin size="small" />}>
      <Flex justify="center" style={{ padding: 32 }}>
        <Spin size="small" />
      </Flex>
    </ChartCard>
  )
}

/** The anomalies query's own loading/error/ready states, distinct from overview's - a failure here must not spin forever. */
function AnomaliesPanel({
  state,
  onRetry
}: {
  state: AnalyticsQueryState<Anomalies>
  onRetry: () => void
}) {
  if (state.status === 'loading') return <PendingPanel title={__('Anomalies')} />
  if (state.status === 'error') {
    return (
      <ChartCard
        title={__('Anomalies')}
        tableView={<AnalyticsErrorState message={state.message} onRetry={onRetry} />}
      >
        <AnalyticsErrorState message={state.message} onRetry={onRetry} />
      </ChartCard>
    )
  }
  // 'logging-disabled' can't happen here in practice (overview already gates the whole page on it),
  // but the union must still be handled exhaustively.
  if (state.status !== 'ready') return null
  return <AnomaliesList anomalies={state.data} />
}

/** Analytics dashboard: filters row + stat tiles, volume trend, delivery/ranking breakdowns, and anomalies. */
export default function AnalyticsPage() {
  const { token } = theme.useToken()
  const preferencesQuery = usePreferences()
  const retentionDays = clampRetentionDays(preferencesQuery.data?.log_retention_days)

  // Resolve a connection id (the analytics dimension) to its human name for the Top connections list.
  const { data: mailSettings } = useMailSettings()
  const connectionNameById = useMemo(
    () =>
      Object.fromEntries(
        (mailSettings?.connections ?? []).map(connection => [connection.id, connection.name])
      ),
    [mailSettings]
  )

  const [rangeOverride, setRangeOverride] = useState<[Dayjs, Dayjs] | null>(null)
  const [bucket, setBucket] = useState<BucketChoice>('auto')

  // -1: toParams() snaps to startOf/endOf day, so a retentionDays-back start would land exactly
  // 1ms inside the backend's retention floor and fail on first load.
  const defaultRange = useMemo<[Dayjs, Dayjs]>(() => {
    const lookbackDays = Math.min(DEFAULT_RETENTION_DAYS, retentionDays) - 1
    return [dayjs().subtract(lookbackDays, 'day'), dayjs()]
  }, [retentionDays])

  const range = rangeOverride ?? defaultRange
  // Shared with the "View in logs" deep links so a chart point's filter matches the visible window.
  const logsDateFrom = range[0].format('YYYY-MM-DD')
  const logsDateTo = range[1].format('YYYY-MM-DD')

  const params = useMemo(() => toParams(range, bucket), [range, bucket])

  const overviewQuery = useOverview(params)
  const anomaliesQuery = useAnomalies(params)

  const overviewState = analyticsQueryState(overviewQuery)
  const anomaliesState = analyticsQueryState(anomaliesQuery)

  if (preferencesQuery.isPending) {
    return <AnalyticsSkeleton />
  }

  // Two-level rhythm: paddingLG separates major page blocks (header, stat tiles, each chart card),
  // padding separates same-level grid items within a block (the two-col ranking grid).
  const pageVars: PageVars = {
    '--page-block-gap': `${token.paddingLG}px`,
    '--page-grid-gap': `${token.padding}px`
  }

  return (
    <Flex vertical gap="large" style={{ padding: token.paddingLG }}>
      <Flex justify="space-between" align="center" wrap gap="middle">
        <Title level={4} style={{ margin: 0 }}>
          {__('Analytics')}
        </Title>
        <AnalyticsFilters
          range={range}
          bucket={bucket}
          retentionDays={retentionDays}
          onRangeChange={setRangeOverride}
          onBucketChange={setBucket}
        />
      </Flex>

      {overviewState.status === 'loading' ? <AnalyticsSkeleton /> : null}
      {overviewState.status === 'error' ? <AnalyticsErrorState message={overviewState.message} /> : null}
      {overviewState.status === 'logging-disabled' ? <LoggingOffState /> : null}

      {overviewState.status === 'ready' ? (
        <div className={cls.page} style={pageVars}>
          <StatTiles overview={overviewState.data} />

          {overviewState.data.total === 0 ? (
            <NoDataState />
          ) : (
            <>
              <VolumeChart
                series={overviewState.data.series}
                dateFrom={logsDateFrom}
                dateTo={logsDateTo}
              />
              <DeliveryBreakdown
                delivery={overviewState.data.delivery}
                dateFrom={logsDateFrom}
                dateTo={logsDateTo}
              />

              <div className={cls.twoCol}>
                <TopList
                  title={__('Top sources')}
                  emptyLabel={__('No source activity in this range.')}
                  groups={overviewState.data.top_sources}
                  logsFilterKey="source_plugin"
                  dateFrom={logsDateFrom}
                  dateTo={logsDateTo}
                />
                <TopList
                  title={__('Top connections')}
                  emptyLabel={__('No connection activity in this range.')}
                  groups={overviewState.data.top_connections}
                  logsFilterKey="connection_id"
                  dateFrom={logsDateFrom}
                  dateTo={logsDateTo}
                  resolveLabel={id => connectionNameById[id] ?? id}
                />
              </div>

              <BusiestHours hours={overviewState.data.busiest_hours} />

              <AnomaliesPanel
                state={anomaliesState}
                onRetry={() => {
                  anomaliesQuery.refetch()
                }}
              />
            </>
          )}
        </div>
      ) : null}
    </Flex>
  )
}
