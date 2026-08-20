import { type ThemeConfig } from 'antd'
import commonConfig from './common'

const darkTheme: ThemeConfig = {
  ...commonConfig,
  token: {
    ...commonConfig.token,
    colorBgLayout: '#0b1020',
    colorBgContainer: '#131a2c',
    colorBgElevated: '#1e293b',
    colorTextBase: '#f8fafc',
    colorBorder: '#334155',
    colorBorderSecondary: '#1f2a3d'
  }
}

export default darkTheme
