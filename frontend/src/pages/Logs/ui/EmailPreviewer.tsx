import type React from 'react'
import { useEffect, useRef } from 'react'

interface EmailPreviewerProps {
  html: string
  isDark: boolean
  style?: React.CSSProperties
}

const DARK_BODY_TEXT_COLOR = '#f8fafc'
const LIGHT_BODY_TEXT_COLOR = '#000000'

/** Prefixes the iframe document with a color-scheme meta tag and a low-specificity `body` color default that the email's own inline/CSS colors still override. */
function buildSrcDoc(html: string, isDark: boolean): string {
  const colorScheme = isDark ? 'dark' : 'light'
  const bodyColor = isDark ? DARK_BODY_TEXT_COLOR : LIGHT_BODY_TEXT_COLOR
  return `<meta name="color-scheme" content="${colorScheme}"><style>body { color: ${bodyColor}; }</style>${html}`
}

/** Iframe-based email HTML preview that re-renders its srcdoc when the html or theme changes. */
function EmailPreviewer({ html, isDark, style }: EmailPreviewerProps) {
  const iframeRef = useRef<HTMLIFrameElement>(null)

  useEffect(() => {
    if (iframeRef.current) {
      iframeRef.current.srcdoc = buildSrcDoc(html, isDark)
    }
  }, [html, isDark])

  return (
    <iframe
      ref={iframeRef}
      title="Email Preview"
      style={{ width: '100%', minHeight: '100vh', ...style }}
      sandbox="allow-scripts allow-same-origin"
    />
  )
}

export default EmailPreviewer
