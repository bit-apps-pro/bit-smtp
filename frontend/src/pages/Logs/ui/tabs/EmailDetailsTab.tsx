import { type LogType } from '@pages/Logs/data/useFetchLogs'
import { Typography } from 'antd'

const { Text } = Typography

interface EmailDetailsTabProps {
  log: LogType
  isLoading?: boolean
}

export default function EmailDetailsTab({ log, isLoading }: EmailDetailsTabProps) {
  if (isLoading || !log) return <Text>Loading...</Text>
  let localSentAt = ''
  if (log?.created_at) {
    try {
      localSentAt = new Date(log.created_at).toLocaleString()
    } catch (e) {
      localSentAt = log.created_at
    }
  }
  return (
    <div>
      <Text strong>Sent At: </Text>
      <Text>{localSentAt}</Text>
      <br />
      <Text strong>Connection: </Text>
      <Text>{log.connection || '—'}</Text>
      <br />
      <Text strong>From: </Text>
      <br />
      <Text strong>To: </Text>
      <Text>{Array.isArray(log?.to_addr) && log.to_addr.length ? log.to_addr.toString() : ''}</Text>
      <br />
      <Text strong>Subject: </Text>
      <Text>{log.subject}</Text>
      {log.message_id ? (
        <>
          <br />
          <Text strong>Provider Message ID: </Text>
          <Text copyable>{log.message_id}</Text>
        </>
      ) : null}
    </div>
  )
}
