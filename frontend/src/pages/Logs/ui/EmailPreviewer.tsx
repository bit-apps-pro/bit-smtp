import type React from 'react'
import { useEffect, useRef } from 'react'
import { theme } from 'antd'

interface EmailPreviewerProps {
  html: string
  style?: React.CSSProperties
}

const PAPER_BACKGROUND = '#ffffff'
const PAPER_TEXT_COLOR = '#000000'

/** Empty sandbox: no scripts, opaque origin — the email HTML can never reach the admin page. */
const SANDBOX_NO_PERMISSIONS = ''

/** Prefixes the email with a light color-scheme and low-specificity white-paper defaults, as mail clients render it, so its own colors stay readable in either admin theme. */
function buildSrcDoc(html: string): string {
  return `<meta name="color-scheme" content="light"><style>body { background: ${PAPER_BACKGROUND}; color: ${PAPER_TEXT_COLOR}; }</style>${html}`
}

/** Sandboxed iframe that previews email HTML on a white page framed by the admin theme. */
function EmailPreviewer({ html, style }: EmailPreviewerProps) {
  const iframeRef = useRef<HTMLIFrameElement>(null)
  const { token } = theme.useToken()

  useEffect(() => {
    if (iframeRef.current) {
      iframeRef.current.srcdoc = buildSrcDoc(html)
    }
  }, [html])

  return (
    <iframe
      ref={iframeRef}
      title="Email Preview"
      style={{
        width: '100%',
        minHeight: '100vh',
        background: PAPER_BACKGROUND,
        border: `1px solid ${token.colorBorderSecondary}`,
        borderRadius: token.borderRadius,
        ...style
      }}
      sandbox={SANDBOX_NO_PERMISSIONS}
    />
  )
}

export default EmailPreviewer
