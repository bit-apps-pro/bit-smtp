import { type ThemeConfig } from 'antd'
import commonConfig, { accentTextConfig } from './common'

/** Logo-blue (#158af8) text shades, antd light palette steps 6/7 — the logo blue itself is only 3.5:1 on white. */
const accentText = accentTextConfig('#0668d1', '#004dab', '#004dab')

const lightTheme: ThemeConfig = {
  ...commonConfig,
  token: {
    ...commonConfig.token,
    colorBgLayout: '#f8fafc',
    colorBgContainer: '#ffffff',
    colorBgElevated: '#ffffff',
    colorBorder: '#e2e8f0',
    colorBorderSecondary: '#eef2f7',
    ...accentText.token
  },
  components: { ...commonConfig.components, ...accentText.components }
}

export default lightTheme
