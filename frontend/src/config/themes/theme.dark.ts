import { type ThemeConfig } from 'antd'
import commonConfig, { accentTextConfig } from './common'

/** Logo-blue text shades (antd dark palette steps 7/8) — the darkAlgorithm defaults fall below WCAG AA on colorBgContainer. */
const accentText = accentTextConfig('#62b7f4', '#8bcef9', '#62b7f4')

const darkTheme: ThemeConfig = {
  ...commonConfig,
  token: {
    ...commonConfig.token,
    colorBgLayout: '#0b1020',
    colorBgContainer: '#131a2c',
    colorBgElevated: '#1e293b',
    colorTextBase: '#f8fafc',
    colorBorder: '#334155',
    colorBorderSecondary: '#1f2a3d',
    ...accentText.token
  },
  components: { ...commonConfig.components, ...accentText.components }
}

export default darkTheme
