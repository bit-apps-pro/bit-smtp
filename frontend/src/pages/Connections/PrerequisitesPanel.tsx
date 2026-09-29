import { useState } from 'react'
import { __, _n, sprintf } from '@common/helpers/i18nwrap'
import { NewTabLink } from '@components/FieldHelp'
import { type ProviderMeta, type ProviderPrerequisite } from '@pages/Connections/types'
import { Collapse, Flex, Typography, theme } from 'antd'

const { Text } = Typography

const PANEL_KEY = 'prerequisites'
const STORAGE_PREFIX = 'bit_smtp_prerequisites_open_'

/** Stored open state for a provider's panel; defaults to open when storage is empty or unavailable. */
function readOpen(providerKey: string): boolean {
  try {
    return localStorage.getItem(STORAGE_PREFIX + providerKey) !== 'false'
  } catch {
    return true
  }
}

/** Remembers the panel's open state for this provider; returns false when storage is unavailable. */
function writeOpen(providerKey: string, open: boolean): boolean {
  try {
    localStorage.setItem(STORAGE_PREFIX + providerKey, String(open))
    return true
  } catch {
    return false
  }
}

/** "1 step" / "N steps" label for the panel header. */
function stepCountLabel(count: number): string {
  return sprintf(_n('%d step', '%d steps', count), count)
}

/** One numbered step: title, one plain sentence, and an optional link to the provider's docs. */
function PrerequisiteStep({ step }: { step: ProviderPrerequisite }) {
  return (
    <li>
      <Flex vertical gap={2}>
        <Text strong>{step.title}</Text>
        <Text type="secondary">{step.body}</Text>
        {step.docUrl ? (
          <NewTabLink
            href={step.docUrl}
            aria-label={sprintf(__('Show me how: %s (opens in a new tab)'), step.title)}
          >
            {__('Show me how')}
          </NewTabLink>
        ) : null}
      </Flex>
    </li>
  )
}

/** Collapsible "Before you start" checklist of the provider's setup steps; open state is remembered per provider. */
export default function PrerequisitesPanel({ provider }: { provider: ProviderMeta }) {
  const { token } = theme.useToken()
  const [open, setOpen] = useState(() => readOpen(provider.key))
  const steps = provider.prerequisites ?? []

  if (steps.length === 0) {
    return null
  }

  /** Applies and remembers a header toggle. */
  const handleChange = (activeKeys: string | string[]) => {
    const next = ([] as string[]).concat(activeKeys).includes(PANEL_KEY)
    setOpen(next)
    writeOpen(provider.key, next)
  }

  return (
    <Collapse
      activeKey={open ? [PANEL_KEY] : []}
      onChange={handleChange}
      style={{ marginBottom: token.marginMD }}
      items={[
        {
          key: PANEL_KEY,
          label: (
            <Flex gap="small" wrap align="baseline">
              <Text strong>{__('Before you start')}</Text>
              <Text type="secondary">{stepCountLabel(steps.length)}</Text>
            </Flex>
          ),
          children: (
            <ol
              style={{
                display: 'flex',
                flexDirection: 'column',
                gap: token.margin,
                margin: 0,
                paddingInlineStart: token.paddingLG
              }}
            >
              {steps.map(step => (
                <PrerequisiteStep key={step.id} step={step} />
              ))}
            </ol>
          )
        }
      ]}
    />
  )
}
