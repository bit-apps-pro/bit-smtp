import { useMemo, useState } from 'react'
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
import { Flex, Spin, Typography } from 'antd'
import dayjs, { type Dayjs } from 'dayjs'
import cls from './AnalyticsPage.module.css'

const { Title } = Typography

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
  const [range, setRange] = useState<[Dayjs, Dayjs]>(() => [dayjs().subtract(30, 'day'), dayjs()])
  const [bucket, setBucket] = useState<BucketChoice>('auto')

  const params = useMemo(() => toParams(range, bucket), [range, bucket])

  const overviewQuery = useOverview(params)
  const anomaliesQuery = useAnomalies(params)

  const overviewState = analyticsQueryState(overviewQuery)
  const anomaliesState = analyticsQueryState(anomaliesQuery)

  return (
    <Flex vertical gap="large" style={{ padding: 24 }}>
      <Flex justify="space-between" align="center" wrap gap="middle">
        <Title level={4} style={{ margin: 0 }}>
          {__('Analytics')}
        </Title>
        <AnalyticsFilters
          range={range}
          bucket={bucket}
          onRangeChange={setRange}
          onBucketChange={setBucket}
        />
      </Flex>

      {overviewState.status === 'loading' ? <AnalyticsSkeleton /> : null}
      {overviewState.status === 'error' ? <AnalyticsErrorState message={overviewState.message} /> : null}
      {overviewState.status === 'logging-disabled' ? <LoggingOffState /> : null}

      {overviewState.status === 'ready' ? (
        <div className={cls.page}>
          <StatTiles overview={overviewState.data} />

          {overviewState.data.total === 0 ? (
            <NoDataState />
          ) : (
            <>
              <VolumeChart series={overviewState.data.series} />
              <DeliveryBreakdown delivery={overviewState.data.delivery} />

              <div className={cls.twoCol}>
                <TopList
                  title={__('Top sources')}
                  emptyLabel={__('No source activity in this range.')}
                  groups={overviewState.data.top_sources}
                />
                <TopList
                  title={__('Top connections')}
                  emptyLabel={__('No connection activity in this range.')}
                  groups={overviewState.data.top_connections}
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
