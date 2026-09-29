/** Logo colors (icons/LogoIcon.tsx, icons/LogoText.tsx); every hue below is derived from or tuned against these. */
export const BRAND = {
  logo: '#158af8',
  wordmark: '#0092e9'
} as const

/** Logo hue (209°) deepened to L45%, the lightest step that keeps white button text at WCAG AA (4.55:1). */
export const PRIMARY_SEED = '#0b76da'

/** Vivid logo tone for non-text indicators (Switch, Checkbox, Radio) where 3:1 is the bar, in both modes. */
export const INDICATOR = {
  base: BRAND.logo,
  hover: '#43a1f9'
} as const

/** Semantic seeds shared by both modes, tuned to sit beside the brand blue: warm amber/coral, a cool-leaning green. */
export const SEMANTIC = {
  success: '#16a05a',
  warning: '#eb9a00',
  serious: '#ec6b35',
  error: '#e0443e',
  info: BRAND.logo
} as const

/** Tinted background + border pair for a status surface (Tag, Alert). */
interface StatusSurface {
  bg: string
  border: string
  text: string
}

/** Danger Button colors: one shade for text/outline and solid fill, with its on-solid ink, AA in both roles. */
interface DangerButton {
  base: string
  hover: string
  active: string
  onSolid: string
}

/** Per-mode surfaces, ink and AA-safe text shades; neutrals are tinted toward the brand hue instead of generic slate. */
export interface ModePalette {
  textBase: string
  textDescription: string
  bgLayout: string
  bgContainer: string
  bgElevated: string
  border: string
  borderSecondary: string
  primaryHover: string
  primaryActive: string
  primaryBg: string
  primaryBgHover: string
  primaryBorder: string
  primaryBorderHover: string
  accentText: string
  accentTextHover: string
  accentTextActive: string
  success: StatusSurface
  warning: StatusSurface
  error: StatusSurface
  danger: DangerButton
  infoText: string
  focusRing: string
  shadow: string
}

export const LIGHT: ModePalette = {
  textBase: '#0e192a',
  textDescription: '#5f6e81',
  bgLayout: '#f3f6fa',
  bgContainer: '#ffffff',
  bgElevated: '#ffffff',
  border: '#d9e1ec',
  borderSecondary: '#e9eef5',
  primaryHover: '#0a67c0',
  primaryActive: '#0858a6',
  primaryBg: '#ecf5fe',
  primaryBgHover: '#d4e9fc',
  primaryBorder: '#a3cdf5',
  primaryBorderHover: '#75b5f0',
  accentText: '#0a67c0',
  accentTextHover: '#0858a6',
  accentTextActive: '#0858a6',
  success: { bg: '#e7f8f0', border: '#9fdfbf', text: '#0b7a41' },
  warning: { bg: '#fff2db', border: '#f7c56e', text: '#8f5400' },
  error: { bg: '#feedec', border: '#f6b0ac', text: '#c0302b' },
  danger: { base: '#c0302b', hover: '#a8271f', active: '#8f211b', onSolid: '#ffffff' },
  infoText: '#0a67c0',
  focusRing: 'rgba(11,118,218,0.16)',
  shadow: '0 4px 16px -4px rgba(14,25,42,0.12)'
}

export const DARK: ModePalette = {
  textBase: '#e8eef7',
  textDescription: '#8392a5',
  bgLayout: '#0b131e',
  bgContainer: '#121c2b',
  bgElevated: '#1b2637',
  border: '#2b3a50',
  borderSecondary: '#1f2b3d',
  primaryHover: '#1471c8',
  primaryActive: '#0d59a0',
  primaryBg: '#11273b',
  primaryBgHover: '#14314d',
  primaryBorder: '#224e77',
  primaryBorderHover: '#27639b',
  accentText: '#68b0f3',
  accentTextHover: '#8fc4f7',
  accentTextActive: '#68b0f3',
  success: { bg: '#0f291c', border: '#1f5c3d', text: '#4cc98a' },
  warning: { bg: '#2e230f', border: '#5e4212', text: '#f5b942' },
  error: { bg: '#2d1617', border: '#6f2a2c', text: '#f27a74' },
  danger: { base: '#f27a74', hover: '#f59a95', active: '#f27a74', onSolid: '#1f0a0a' },
  infoText: '#68b0f3',
  focusRing: 'rgba(104,176,243,0.24)',
  shadow: '0 6px 20px -6px rgba(0,0,0,0.55)'
}

/** Neutral tile for providers without a brand color (generic SMTP, sendmail). */
export const NEUTRAL_ACCENT = '#5b6b82'
