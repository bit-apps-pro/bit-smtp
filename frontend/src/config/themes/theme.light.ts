import { theme } from 'antd'
import { buildModeTheme } from './common'
import { LIGHT } from './palette'

const lightTheme = buildModeTheme(LIGHT, theme.defaultAlgorithm)

export default lightTheme
