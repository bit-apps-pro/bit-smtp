import { type ThemeConfig } from 'antd'

const fontFamily =
  "'Outfit',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,'Noto Sans',sans-serif,'Apple Color Emoji','Segoe UI Emoji','Segoe UI Symbol','Noto Color Emoji'"

const commonConfig: ThemeConfig = {
  token: {
    fontFamily,
    borderRadius: 8,
    borderRadiusLG: 12,
    colorPrimary: '#4f46e5',
    colorInfo: '#7c3aed',
    colorSuccess: '#16a34a',
    colorWarning: '#d97706',
    colorError: '#dc2626',
    colorTextBase: '#0f172a',
    controlHeight: 38,
    boxShadowSecondary: '0 4px 16px -4px rgba(15,23,42,0.12)'
  }
}
export default commonConfig
