import { type ThemeConfig } from 'antd'
import { INDICATOR, type ModePalette, PRIMARY_SEED, SEMANTIC } from './palette'

const fontFamily =
  "'Outfit',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,'Noto Sans',sans-serif,'Apple Color Emoji','Segoe UI Emoji','Segoe UI Symbol','Noto Color Emoji'"

const commonConfig: ThemeConfig = {
  token: {
    fontFamily,
    borderRadius: 8,
    borderRadiusLG: 12,
    colorPrimary: PRIMARY_SEED,
    colorInfo: SEMANTIC.info,
    colorSuccess: SEMANTIC.success,
    colorWarning: SEMANTIC.warning,
    colorError: SEMANTIC.error,
    controlHeight: 38
  }
}

/** Status Bg/Border/Text map tokens from the palette, replacing antd's generated tints (muddy for the green seed, below AA as text). */
function statusTokens(p: ModePalette): ThemeConfig['token'] {
  return {
    colorSuccessBg: p.success.bg,
    colorSuccessBorder: p.success.border,
    colorSuccessText: p.success.text,
    colorWarningBg: p.warning.bg,
    colorWarningBorder: p.warning.border,
    colorWarningText: p.warning.text,
    colorErrorBg: p.error.bg,
    colorErrorBorder: p.error.border,
    colorErrorText: p.error.text,
    colorInfoText: p.infoText
  }
}

/** Brand-hue tints for selected/soft surfaces; antd's generated step 1-4 drift toward cyan. Info Alerts share them. */
function tintTokens(p: ModePalette): ThemeConfig['token'] {
  return {
    colorPrimaryBg: p.primaryBg,
    colorPrimaryBgHover: p.primaryBgHover,
    colorPrimaryBorder: p.primaryBorder,
    colorPrimaryBorderHover: p.primaryBorderHover,
    colorInfoBg: p.primaryBg,
    colorInfoBgHover: p.primaryBgHover,
    colorInfoBorder: p.primaryBorder,
    colorInfoBorderHover: p.primaryBorderHover
  }
}

/** Accent-text and link tokens from shades that stay readable on the mode's surfaces; algorithm defaults fall below AA. */
function accentTokens(p: ModePalette): ThemeConfig['token'] {
  return {
    colorPrimaryText: p.accentText,
    colorPrimaryTextHover: p.accentTextHover,
    colorPrimaryTextActive: p.accentTextActive,
    colorLink: p.accentText,
    colorLinkHover: p.accentTextHover,
    colorLinkActive: p.accentTextActive
  }
}

const indicatorOverride = { colorPrimary: INDICATOR.base, colorPrimaryHover: INDICATOR.hover }

/** Component overrides: AA text for status Tags and selected Tabs, AA primary/danger Button states, and logo-blue indicators. */
function componentOverrides(p: ModePalette): ThemeConfig['components'] {
  return {
    Switch: indicatorOverride,
    Checkbox: indicatorOverride,
    Radio: indicatorOverride,
    Tabs: {
      itemSelectedColor: p.accentText,
      itemHoverColor: p.accentTextHover,
      itemActiveColor: p.accentTextActive
    },
    Tag: {
      colorSuccess: p.success.text,
      colorWarning: p.warning.text,
      colorError: p.error.text,
      colorInfo: p.infoText
    },
    Button: {
      colorPrimaryHover: p.primaryHover,
      colorPrimaryActive: p.primaryActive,
      defaultHoverColor: p.accentTextHover,
      defaultHoverBorderColor: p.accentTextHover,
      defaultActiveColor: p.accentTextActive,
      defaultActiveBorderColor: p.accentTextActive,
      primaryShadow: `0 2px 0 ${p.focusRing}`,
      colorError: p.danger.base,
      colorErrorHover: p.danger.hover,
      colorErrorActive: p.danger.active,
      colorErrorBorderHover: p.danger.hover,
      dangerColor: p.danger.onSolid
    }
  }
}

/** Builds a complete light or dark ThemeConfig from a mode palette layered over the shared seed tokens. */
export function buildModeTheme(p: ModePalette): ThemeConfig {
  return {
    token: {
      ...commonConfig.token,
      colorTextBase: p.textBase,
      colorTextDescription: p.textDescription,
      colorBgLayout: p.bgLayout,
      colorBgContainer: p.bgContainer,
      colorBgElevated: p.bgElevated,
      colorBorder: p.border,
      colorBorderSecondary: p.borderSecondary,
      controlOutline: p.focusRing,
      boxShadowSecondary: p.shadow,
      ...tintTokens(p),
      ...accentTokens(p),
      ...statusTokens(p)
    },
    components: componentOverrides(p)
  }
}

export default commonConfig
