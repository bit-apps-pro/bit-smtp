import { type DeliveryStatusKey } from '@common/glossary'
import { AA_GRAPHIC, contrast } from '@config/themes/contrast'
import { modePalette } from '@config/themes/palette'
import { describe, expect, it } from 'vitest'
import { deliveryStatusColor, otherGray, rankingHue, volumeSeriesColors } from './palette'

const BREAKDOWN_ROWS: ReadonlyArray<DeliveryStatusKey> = [
  'delivered',
  'deferred',
  'bounced',
  'blocked',
  'spam',
  'accepted',
  'pending'
]

describe('analytics chart palette', () => {
  it.each([
    ['light', false],
    ['dark', true]
  ])('keeps every %s chart mark at 3:1 on the card surface', (_mode, isDark) => {
    const surface = modePalette(isDark).bgContainer
    const marks = [
      ...Object.values(volumeSeriesColors(isDark)),
      ...BREAKDOWN_ROWS.map(row => deliveryStatusColor(row, isDark)),
      rankingHue(isDark),
      otherGray(isDark)
    ]
    marks.forEach(mark => {
      expect(contrast(mark, surface)).toBeGreaterThanOrEqual(AA_GRAPHIC)
    })
  })
})
