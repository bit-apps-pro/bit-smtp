import { useTheme } from '@config/themes/theme.provider'
import { type ProviderVisual } from '@pages/Connections/providerVisuals'
import { Flex, theme } from 'antd'

const PLATE_PADDING = 2
const COMPACT_SIZE = 32

/** Decorative provider logo, or its initial on the brand accent; logos sit on a light plate in dark mode so dark glyphs stay visible. */
export default function ProviderLogo({ visual, size }: { visual: ProviderVisual; size: number }) {
  const { token } = theme.useToken()
  const { isDark } = useTheme()

  if (visual.logo) {
    return (
      <Flex
        align="center"
        justify="center"
        aria-hidden="true"
        data-testid="provider-logo"
        style={{
          flexShrink: 0,
          borderRadius: token.borderRadiusSM,
          ...(isDark && { backgroundColor: token.colorWhite, padding: PLATE_PADDING })
        }}
      >
        <img src={visual.logo} alt="" width={size} height={size} style={{ objectFit: 'contain' }} />
      </Flex>
    )
  }

  return (
    <Flex
      align="center"
      justify="center"
      aria-hidden="true"
      style={{
        width: size,
        height: size,
        flexShrink: 0,
        borderRadius: token.borderRadius,
        backgroundColor: visual.accent,
        color: token.colorWhite,
        fontSize: size < COMPACT_SIZE ? token.fontSizeSM : undefined,
        fontWeight: token.fontWeightStrong
      }}
    >
      {visual.initial}
    </Flex>
  )
}
