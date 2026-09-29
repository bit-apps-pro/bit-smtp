import { __ } from '@common/helpers/i18nwrap'
import DebugOutput from '@components/DebugOutput/DebugOutput'
import { type LogType } from '@pages/Logs/data/useFetchLogs'
import { Typography } from 'antd'

interface DebugOutputTabProps {
  log: LogType
}

/** The raw transport transcript for this send, or an explanation when none was captured. */
export default function DebugOutputTab({ log }: DebugOutputTabProps) {
  return Array.isArray(log?.debug_info) && log.debug_info.length ? (
    <DebugOutput log={log.debug_info} />
  ) : (
    <Typography.Text type="secondary">
      {__(
        "No technical details were saved for this email. When saved, they show the mail server's replies and help explain a failed send."
      )}
    </Typography.Text>
  )
}
