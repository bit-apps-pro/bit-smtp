import { type ThemeConfig } from 'antd'

const fontFamily =
  "'Outfit',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,'Noto Sans',sans-serif,'Apple Color Emoji','Segoe UI Emoji','Segoe UI Symbol','Noto Color Emoji'"

const commonConfig: ThemeConfig = {
  token: {
    fontFamily,
    borderRadius: 8,
    borderRadiusLG: 12,
    colorPrimary: '#0668d1',
    colorInfo: '#0092e9',
    colorSuccess: '#16a34a',
    colorWarning: '#d97706',
    colorError: '#dc2626',
    colorTextBase: '#0f172a',
    controlHeight: 38,
    boxShadowSecondary: '0 4px 16px -4px rgba(15,23,42,0.12)'
  }
}

/** Builds a theme's accent-text tokens and Tabs colors from shades that stay readable on its surface. */
export function accentTextConfig(text: string, hover: string, active: string): ThemeConfig {
  return {
    token: {
      colorPrimaryText: text,
      colorPrimaryTextHover: hover,
      colorPrimaryTextActive: active,
      colorLink: text,
      colorLinkHover: hover,
      colorLinkActive: active
    },
    components: {
      Tabs: { itemSelectedColor: text, itemHoverColor: hover, itemActiveColor: active }
    }
  }
}

export default commonConfig
