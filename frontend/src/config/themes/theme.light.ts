import { type ThemeConfig } from 'antd'
import commonConfig from './common'

const lightTheme: ThemeConfig = {
  ...commonConfig,
  token: {
    ...commonConfig.token,
    colorBgLayout: '#f8fafc',
    colorBgContainer: '#ffffff',
    colorBgElevated: '#ffffff',
    colorBorder: '#e2e8f0',
    colorBorderSecondary: '#eef2f7'
  }
}

export default lightTheme
