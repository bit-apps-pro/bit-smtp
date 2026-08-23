import { Flex, theme } from 'antd'
import { type LucideIcon } from 'lucide-react'

interface StatusDotProps {
  active: boolean
  // Override the "on" color (e.g. a warning tone); defaults to success. Off is always muted.
  activeColor?: string
}

/** 6px status dot: success color when the feature it labels is on, quaternary muted when off. */
export function StatusDot({ active, activeColor }: StatusDotProps) {
  const { token } = theme.useToken()

  return (
    <span
      aria-hidden="true"
      style={{
        width: 6,
        height: 6,
        borderRadius: '50%',
        display: 'inline-block',
        backgroundColor: active ? activeColor ?? token.colorSuccess : token.colorTextQuaternary
      }}
    />
  )
}

interface TabLabelProps {
  icon: LucideIcon
  label: string
  // Omitted entirely (not just false) for tabs that don't reflect live on/off state.
  dotActive?: boolean
}

/** Settings tab label: icon + text, plus a live status dot for tabs that reflect on/off state. */
export default function TabLabel({ icon: Icon, label, dotActive }: TabLabelProps) {
  return (
    <Flex align="center" gap={6}>
      <Icon size={15} strokeWidth={1.75} aria-hidden="true" />
      <span>{label}</span>
      {dotActive !== undefined && <StatusDot active={dotActive} />}
    </Flex>
  )
}
