import { useMemo } from 'react'
import { useTheme } from '@config/themes/theme.provider'
import { type LogType } from '@pages/Logs/data/useFetchLogs'
import EmailPreviewer from '@pages/Logs/ui/EmailPreviewer'
import dompurify from 'dompurify'

interface MailBodyTabProps {
  log: LogType
}

export default function MailBodyTab({ log }: MailBodyTabProps) {
  const { isDark } = useTheme()
  const message = log?.details?.message || ''
  // Sanitization is independent of theme; memoize so a dark-mode toggle doesn't re-run DOMPurify.
  const mailBodyHtml = useMemo(
    () =>
      dompurify.sanitize(message, {
        ALLOWED_TAGS: [
          'b',
          'i',
          'em',
          'strong',
          'u',
          'a',
          'p',
          'br',
          'div',
          'span',
          'img',
          'table',
          'thead',
          'tbody',
          'tr',
          'td',
          'th',
          'ul',
          'ol',
          'li',
          'blockquote',
          'pre',
          'code',
          'hr',
          'h1',
          'h2',
          'h3',
          'h4',
          'h5',
          'h6'
        ],
        ALLOWED_ATTR: [
          'href',
          'src',
          'alt',
          'title',
          'style',
          'width',
          'height',
          'align',
          'target',
          'rel'
        ],
        ADD_ATTR: ['class']
      }),
    [message]
  )
  return <EmailPreviewer html={mailBodyHtml} isDark={isDark} />
}
