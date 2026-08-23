import { Link } from 'react-router-dom'
import { __ } from '@common/helpers/i18nwrap'
import { Button, Empty, Flex, Skeleton, Typography } from 'antd'

const { Text } = Typography

/** Shown while the overview query has no cached data yet (first load only - refetches keep the prior render). */
export function AnalyticsSkeleton() {
  return (
    <Flex vertical gap="middle" style={{ padding: '8px 0' }}>
      <Skeleton.Button active shape="round" style={{ width: 220, height: 36 }} />
      <Flex gap="middle" wrap>
        {[0, 1, 2, 3].map(key => (
          <Skeleton.Input key={key} active style={{ width: 220, height: 96 }} />
        ))}
      </Flex>
      <Skeleton.Node active style={{ width: '100%', height: 280 }}>
        {null}
      </Skeleton.Node>
    </Flex>
  )
}

/** Logging is off: this is an expected, non-error business state, keyed on the response `code`, not `status`. */
export function LoggingOffState() {
  return (
    <Empty
      image={Empty.PRESENTED_IMAGE_SIMPLE}
      description={
        <Flex vertical align="center" gap={4}>
          <Text strong>{__('Enable logging to see analytics')}</Text>
          <Text type="secondary">{__('Bit SMTP analytics are built from retained email logs.')}</Text>
        </Flex>
      }
      style={{ padding: '64px 0' }}
    >
      <Link to="/settings">
        <Button type="primary">{__('Go to Settings')}</Button>
      </Link>
    </Empty>
  )
}

/** No sends recorded for the filtered range - the stat tiles already read zero, so no chart is shown. */
export function NoDataState() {
  return (
    <Empty
      image={Empty.PRESENTED_IMAGE_SIMPLE}
      description={__('No sends in this range. Try widening the date range.')}
      style={{ padding: '64px 0' }}
    />
  )
}

/** A genuine failure (invalid range, DB error) - inline, not a full-page replacement; `onRetry` adds a retry button. */
export function AnalyticsErrorState({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <Empty
      image={Empty.PRESENTED_IMAGE_SIMPLE}
      description={
        <Flex vertical align="center" gap={4}>
          <Text strong>{__('Could not load analytics')}</Text>
          <Text type="secondary">{message}</Text>
        </Flex>
      }
      style={{ padding: '64px 0' }}
    >
      {onRetry ? <Button onClick={onRetry}>{__('Retry')}</Button> : null}
    </Empty>
  )
}

/** "Not enough history to compare yet" - shown in place of the anomalies list when prior-period coverage is incomplete. */
export function InsufficientHistoryState() {
  return <Text type="secondary">{__('Not enough history to compare yet.')}</Text>
}
