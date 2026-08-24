import { type SeriesBucket } from '@pages/Analytics/types'
import { describe, expect, it } from 'vitest'
import { buildVolumeBucketMetrics } from './VolumeChart'

const colors = { total: '#111', accepted: '#222', delivered: '#333', failed: '#444' }

const bucket: SeriesBucket = {
  bucket: '2026-08-20',
  label: 'Aug 20',
  total: 100,
  accepted: 90,
  failed: 10,
  delivered: 72,
  verified_delivery: 80
}

describe('buildVolumeBucketMetrics', () => {
  it('lists raw counts (with series colors) plus derived accepted/delivered rates', () => {
    const metrics = buildVolumeBucketMetrics(bucket, colors)

    expect(metrics).toEqual([
      { label: 'Total', value: '100', color: '#111' },
      { label: 'Accepted', value: '90', color: '#222' },
      { label: 'Delivered', value: '72', color: '#333' },
      { label: 'Failed', value: '10', color: '#444' },
      { label: 'Accepted rate', value: '90.0%' },
      { label: 'Delivered rate', value: '90.0%' }
    ])
  })

  it('shows an em dash for both rates when their denominators are zero', () => {
    const emptyBucket: SeriesBucket = { ...bucket, total: 0, accepted: 0, verified_delivery: 0 }

    const metrics = buildVolumeBucketMetrics(emptyBucket, colors)

    expect(metrics.find(metric => metric.label === 'Accepted rate')?.value).toBe('—')
    expect(metrics.find(metric => metric.label === 'Delivered rate')?.value).toBe('—')
  })
})
