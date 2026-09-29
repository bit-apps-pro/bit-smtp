import { describe, expect, it } from 'vitest'
import commonConfig from './common'
import { BRAND, DARK, LIGHT, PRIMARY_SEED } from './palette'
import darkTheme from './theme.dark'
import lightTheme from './theme.light'

/** WCAG relative luminance of a #rrggbb color. */
function luminance(hex: string): number {
  const [r, g, b] = [1, 3, 5].map(i => {
    const c = parseInt(hex.slice(i, i + 2), 16) / 255
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
  })
  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

/** WCAG contrast ratio between two #rrggbb colors. */
function contrast(a: string, b: string): number {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x)
  return (hi + 0.05) / (lo + 0.05)
}

const AA = 4.5

describe('theme tokens', () => {
  it('seeds primary from the logo hue and uses the logo blue as info', () => {
    expect(commonConfig.token?.colorPrimary).toBe(PRIMARY_SEED)
    expect(commonConfig.token?.colorInfo).toBe(BRAND.logo)
    expect(commonConfig.token?.borderRadius).toBe(8)
    expect(commonConfig.token?.borderRadiusLG).toBe(12)
  })

  it('light and dark inherit common and set their own surfaces', () => {
    expect(lightTheme.token?.colorPrimary).toBe(PRIMARY_SEED)
    expect(darkTheme.token?.colorPrimary).toBe(PRIMARY_SEED)
    expect(lightTheme.token?.colorBgLayout).toBe(LIGHT.bgLayout)
    expect(darkTheme.token?.colorBgLayout).toBe(DARK.bgLayout)
    expect(darkTheme.token?.colorTextBase).toBe(DARK.textBase)
  })

  it('keeps white text on the primary button at AA in every light state', () => {
    const fills = [PRIMARY_SEED, LIGHT.primaryHover, LIGHT.primaryActive]
    fills.forEach(bg => {
      expect(contrast('#ffffff', bg)).toBeGreaterThanOrEqual(AA)
    })
  })

  it.each([
    ['light', LIGHT],
    ['dark', DARK]
  ])('keeps %s accent, muted, status and danger text at AA on its surfaces', (_mode, p) => {
    const surfaceTexts = [p.accentText, p.accentTextHover, p.infoText, p.textDescription, p.danger.base]
    surfaceTexts.forEach(text => {
      expect(contrast(text, p.bgContainer)).toBeGreaterThanOrEqual(AA)
      expect(contrast(text, p.bgLayout)).toBeGreaterThanOrEqual(AA)
    })
    const statuses = [p.success, p.warning, p.error]
    statuses.forEach(status => {
      expect(contrast(status.text, status.bg)).toBeGreaterThanOrEqual(AA)
    })
    const dangerFills = [p.danger.base, p.danger.hover, p.danger.active]
    dangerFills.forEach(fill => {
      expect(contrast(p.danger.onSolid, fill)).toBeGreaterThanOrEqual(AA)
    })
  })
})
