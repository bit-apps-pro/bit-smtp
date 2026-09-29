import { theme } from 'antd'
import { describe, expect, it } from 'vitest'
import commonConfig from './common'
import { AA_GRAPHIC, AA_TEXT, contrast } from './contrast'
import { BRAND, DARK, LIGHT, PRIMARY_SEED } from './palette'
import darkTheme from './theme.dark'
import lightTheme from './theme.light'

const WHITE = '#ffffff'
const PRIMARY_SEED_MARGIN = 4.8

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

  it('keeps white on the light primary seed above AA with margin', () => {
    expect(contrast(WHITE, PRIMARY_SEED)).toBeGreaterThanOrEqual(PRIMARY_SEED_MARGIN)
  })

  it.each([
    ['light', lightTheme, LIGHT],
    ['dark', darkTheme, DARK]
  ])('pins the %s primary fill past the mode algorithm', (_mode, config, p) => {
    expect(theme.getDesignToken(config).colorPrimary).toBe(p.primary)
  })

  it.each([
    ['light', LIGHT],
    ['dark', DARK]
  ])('keeps white %s primary button text at AA in every state', (_mode, p) => {
    ;[p.primary, p.primaryHover, p.primaryActive].forEach(fill => {
      expect(contrast(WHITE, fill)).toBeGreaterThanOrEqual(AA_TEXT)
    })
    expect(contrast(p.primary, p.bgContainer)).toBeGreaterThanOrEqual(AA_GRAPHIC)
  })

  it.each([
    ['light', LIGHT],
    ['dark', DARK]
  ])('keeps %s accent, muted, status and danger text at AA on its surfaces', (_mode, p) => {
    const surfaceTexts = [p.accentText, p.accentTextHover, p.infoText, p.textDescription, p.danger.base]
    surfaceTexts.forEach(text => {
      expect(contrast(text, p.bgContainer)).toBeGreaterThanOrEqual(AA_TEXT)
      expect(contrast(text, p.bgLayout)).toBeGreaterThanOrEqual(AA_TEXT)
    })
    const statuses = [p.success, p.warning, p.serious, p.error]
    statuses.forEach(status => {
      expect(contrast(status.text, status.bg)).toBeGreaterThanOrEqual(AA_TEXT)
    })
    const dangerFills = [p.danger.base, p.danger.hover, p.danger.active]
    dangerFills.forEach(fill => {
      expect(contrast(p.danger.onSolid, fill)).toBeGreaterThanOrEqual(AA_TEXT)
    })
  })

  it.each([
    ['light', LIGHT],
    ['dark', DARK]
  ])('keeps %s status marks at the 3:1 non-text minimum on its surfaces', (_mode, p) => {
    Object.values(p.statusMark).forEach(mark => {
      expect(contrast(mark, p.bgContainer)).toBeGreaterThanOrEqual(AA_GRAPHIC)
      expect(contrast(mark, p.bgLayout)).toBeGreaterThanOrEqual(AA_GRAPHIC)
    })
  })
})
