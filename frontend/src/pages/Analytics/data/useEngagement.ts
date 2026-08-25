import { useAnalyticsQuery } from '@pages/Analytics/data/useAnalytics'
import { type AnalyticsRangeParams, type Engagement } from '@pages/Analytics/types'

/** Open/click engagement totals with the honest human-vs-automated split for the filtered range. */
export default function useEngagement(params: AnalyticsRangeParams) {
  return useAnalyticsQuery<Engagement>('engagement', params)
}
