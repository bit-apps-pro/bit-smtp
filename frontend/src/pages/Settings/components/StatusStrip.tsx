import { type CSSProperties } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import { StatusDot } from '@pages/Settings/components/TabLabel'
import { theme } from 'antd'
import cls from './StatusStrip.module.css'

type ChipVars = CSSProperties & Record<`--chip-${string}`, string>

type ChipTone = 'on' | 'off' | 'value'

interface ChipProps {
  label: string
  value: string
  // Omitted for value chips (Retention/Timeout); present for on/off readouts.
  on?: boolean
}

/** Map on/off/undefined to the chip's visual tone: undefined means a plain value chip. */
function toneOf(on: boolean | undefined): ChipTone {
  if (on === undefined) {
    return 'value'
  }

  return on ? 'on' : 'off'
}

/** One readout pill: uppercase micro-label + emphasized value, tinted by on/off/value tone. */
function StatusChip({ label, value, on }: ChipProps) {
  const { token } = theme.useToken()

  // "On" glows a faint success wash; "off" recedes; value chips read as crisp neutral data.
  const swatches: Record<ChipTone, { bg: string; border: string; label: string; value: string }> = {
    on: {
      bg: token.colorSuccessBg,
      border: token.colorSuccessBorder,
      label: token.colorTextTertiary,
      value: token.colorSuccessText
    },
    off: {
      bg: token.colorFillQuaternary,
      border: token.colorBorderSecondary,
      label: token.colorTextQuaternary,
      value: token.colorTextSecondary
    },
    value: {
      bg: token.colorFillTertiary,
      border: token.colorBorderSecondary,
      label: token.colorTextTertiary,
      value: token.colorText
    }
  }
  const swatch = swatches[toneOf(on)]
  const vars: ChipVars = {
    '--chip-bg': swatch.bg,
    '--chip-border': swatch.border,
    '--chip-label': swatch.label,
    '--chip-value': swatch.value
  }

  return (
    <span className={cls.chip} style={vars}>
      {on !== undefined && <StatusDot active={on} />}
      <span className={cls.label}>{label}</span>
      <span className={cls.value}>{value}</span>
    </span>
  )
}

interface StatusStripProps {
  loggingEnabled: boolean
  retentionDays: number
  timeoutSeconds: number
  notificationsEnabled: boolean
  healthEnabled: boolean
}

/** At-a-glance readout above the settings tabs, derived from already-loaded/watched settings state. */
export default function StatusStrip({
  loggingEnabled,
  retentionDays,
  timeoutSeconds,
  notificationsEnabled,
  healthEnabled
}: StatusStripProps) {
  return (
    <div role="group" aria-label={__('Settings status')} className={cls.strip}>
      <StatusChip
        label={__('Logging')}
        value={loggingEnabled ? __('On') : __('Off')}
        on={loggingEnabled}
      />
      <StatusChip label={__('Retention')} value={`${retentionDays}d`} />
      <StatusChip label={__('Timeout')} value={`${timeoutSeconds}s`} />
      <StatusChip
        label={__('Notifications')}
        value={notificationsEnabled ? __('On') : __('Off')}
        on={notificationsEnabled}
      />
      <StatusChip label={__('Health')} value={healthEnabled ? __('On') : __('Off')} on={healthEnabled} />
    </div>
  )
}
