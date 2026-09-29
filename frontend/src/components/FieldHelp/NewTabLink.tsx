import { type CSSProperties } from 'react'
import { ExportOutlined } from '@ant-design/icons'
import { __ } from '@common/helpers/i18nwrap'
import { Typography } from 'antd'
import { type LinkProps } from 'antd/es/typography/Link'

/** Hides text visually while keeping it in the accessibility tree. */
const VISUALLY_HIDDEN: CSSProperties = {
  position: 'absolute',
  width: 1,
  height: 1,
  overflow: 'hidden',
  clip: 'rect(0 0 0 0)',
  clipPath: 'inset(50%)',
  whiteSpace: 'nowrap'
}

export type NewTabLinkProps = Omit<LinkProps, 'target' | 'rel'> & { href: string }

/** External link that opens in a new tab, with a new-tab icon and a screen-reader note saying so. */
export default function NewTabLink({ children, ...linkProps }: NewTabLinkProps) {
  return (
    // eslint-disable-next-line react/jsx-props-no-spreading -- thin wrapper forwards every Link prop
    <Typography.Link {...linkProps} target="_blank" rel="noopener noreferrer">
      {children} <ExportOutlined aria-hidden="true" style={{ fontSize: '0.85em' }} />
      <span style={VISUALLY_HIDDEN}> {__('(opens in a new tab)')}</span>
    </Typography.Link>
  )
}
