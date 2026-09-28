import { __ } from '@common/helpers/i18nwrap'
import { Flex, Typography, theme } from 'antd'
import { type LucideIcon } from 'lucide-react'

const { Text } = Typography

interface StatusDotProps {
  active: boolean
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
  enabled?: boolean
}

/** Settings menu label: icon + text, plus a muted "Off" hint when the section's feature is disabled. */
export default function TabLabel({ icon: Icon, label, enabled }: TabLabelProps) {
  return (
    <Flex align="center" gap={10}>
      <Icon size={16} strokeWidth={1.75} aria-hidden="true" />
      <span style={{ flex: 1 }}>{label}</span>
      {enabled === false && (
        <Text type="secondary" style={{ fontSize: 12, fontWeight: 400 }}>
          {__('Off')}
        </Text>
      )}
    </Flex>
  )
}
