import { __ } from '@common/helpers/i18nwrap'
import { type AnalyticsBucket } from '@pages/Analytics/types'
import { DatePicker, Flex, Segmented } from 'antd'
import dayjs, { type Dayjs } from 'dayjs'

const { RangePicker } = DatePicker

export type BucketChoice = AnalyticsBucket | 'auto'

const BUCKET_OPTIONS: Array<{ label: string; value: BucketChoice }> = [
  { label: __('Auto'), value: 'auto' },
  { label: __('Day'), value: 'day' },
  { label: __('Week'), value: 'week' }
]

function rangePresets(): Array<{ label: string; value: [Dayjs, Dayjs] }> {
  const now = dayjs()
  return [
    { label: __('Last 7 days'), value: [now.subtract(7, 'day'), now] },
    { label: __('Last 30 days'), value: [now.subtract(30, 'day'), now] },
    { label: __('Last 90 days'), value: [now.subtract(90, 'day'), now] },
    { label: __('Month to date'), value: [now.startOf('month'), now] }
  ]
}

interface AnalyticsFiltersProps {
  range: [Dayjs, Dayjs]
  bucket: BucketChoice
  onRangeChange: (range: [Dayjs, Dayjs]) => void
  onBucketChange: (bucket: BucketChoice) => void
}

/** The one filter row above the whole dashboard - date range + bucket, scoping every chart below it. */
export default function AnalyticsFilters({
  range,
  bucket,
  onRangeChange,
  onBucketChange
}: AnalyticsFiltersProps) {
  return (
    <Flex align="center" gap="middle" wrap>
      <RangePicker
        value={range}
        presets={rangePresets()}
        allowClear={false}
        onChange={dates => {
          if (dates && dates[0] && dates[1]) {
            onRangeChange([dates[0], dates[1]])
          }
        }}
      />
      <Segmented
        options={BUCKET_OPTIONS}
        value={bucket}
        onChange={value => onBucketChange(value as BucketChoice)}
      />
    </Flex>
  )
}
