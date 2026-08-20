import { describe, expect, it } from 'vitest'
import commonConfig from './common'
import darkTheme from './theme.dark'
import lightTheme from './theme.light'

describe('theme tokens', () => {
  it('uses the indigo primary and violet info accent', () => {
    expect(commonConfig.token?.colorPrimary).toBe('#4f46e5')
    expect(commonConfig.token?.colorInfo).toBe('#7c3aed')
    expect(commonConfig.token?.borderRadius).toBe(8)
    expect(commonConfig.token?.borderRadiusLG).toBe(12)
  })
  it('light and dark inherit common and set their surfaces', () => {
    expect(lightTheme.token?.colorPrimary).toBe('#4f46e5')
    expect(lightTheme.token?.colorBgLayout).toBe('#f8fafc')
    expect(darkTheme.token?.colorPrimary).toBe('#4f46e5')
    expect(darkTheme.token?.colorBgLayout).toBe('#0b1020')
    expect(darkTheme.token?.colorTextBase).toBe('#f8fafc')
  })
})
