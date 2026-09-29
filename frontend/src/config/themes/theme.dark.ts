import { theme } from 'antd'
import { buildModeTheme } from './common'
import { DARK } from './palette'

const darkTheme = buildModeTheme(DARK, theme.darkAlgorithm)

export default darkTheme
