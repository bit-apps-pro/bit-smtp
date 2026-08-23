import { type ReactNode } from 'react'
import { Divider, Flex, Typography, theme } from 'antd'
import cls from './SettingsPanel.module.css'

const { Text } = Typography

// Reading-column cap (px), shared with SettingsPage's sticky save bar so it aligns under this
// column; SettingsPage imports this constant rather than duplicating the number.
export const CONTENT_MAX_WIDTH = 720

interface SettingsPanelProps {
  intro: string
  children: ReactNode
}

/** Shared tab-panel shell: a muted intent sentence over a maxWidth field stack (the tab is the container). */
export default function SettingsPanel({ intro, children }: SettingsPanelProps) {
  const { token } = theme.useToken()

  return (
    <Flex
      vertical
      className={cls.panel}
      style={{ paddingBlock: token.paddingLG, maxWidth: CONTENT_MAX_WIDTH }}
    >
      <Text type="secondary" className={cls.intro} style={{ marginBottom: token.marginLG }}>
        {intro}
      </Text>
      <Flex vertical>{children}</Flex>
    </Flex>
  )
}

/** Subtle hairline divider between field rows or subgroups within a settings panel. */
export function PanelDivider() {
  const { token } = theme.useToken()

  return <Divider style={{ margin: `${token.paddingSM}px 0` }} />
}
