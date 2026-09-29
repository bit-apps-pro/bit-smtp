import { __ } from '@common/helpers/i18nwrap'
import cls from './DebugOutput.module.css'

type Props = {
  log: string[]
  className?: string
}

/** Pairs each line with a stable key made of its text and how often that text appeared before it. */
function keyedLines(lines: string[]) {
  const seen = new Map<string, number>()
  return lines.map(text => {
    const occurrence = seen.get(text) ?? 0
    seen.set(text, occurrence + 1)
    return { key: `${text}#${occurrence}`, text }
  })
}

/** Terminal-style panel that lists raw debug lines from a send attempt. */
function DebugOutput({ log = [], className = '' }: Props) {
  return (
    <div className={`${cls.terminal} ${className}`.trim()}>
      <div className={cls.header}>
        <div className={cls.title}>{__('Debug output')}</div>
      </div>

      <div className={cls.content} role="log" aria-live="polite">
        {(!Array.isArray(log) || log.length === 0) && (
          <div className={cls.empty}>{__('No debug output yet.')}</div>
        )}
        {Array.isArray(log) && log.length > 0 && (
          <pre className={cls.pre}>
            {keyedLines(log).map(line => (
              <div key={line.key} className={cls.line}>
                {line.text}
              </div>
            ))}
          </pre>
        )}
      </div>
    </div>
  )
}

export default DebugOutput
