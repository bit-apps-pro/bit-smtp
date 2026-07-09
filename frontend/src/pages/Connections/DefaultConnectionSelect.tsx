import { __ } from '@common/helpers/i18nwrap'
import { type Connection } from '@pages/Connections/types'
import { Select } from 'antd'

export default function DefaultConnectionSelect({
  connections,
  defaultId,
  onChange
}: {
  connections: Connection[]
  defaultId: string
  onChange: (id: string) => void
}) {
  return (
    <Select
      aria-label={__('Default connection')}
      style={{ width: '100%' }}
      value={defaultId}
      onChange={id => onChange(id)}
      options={connections.map(connection => ({ value: connection.id, label: connection.name }))}
    />
  )
}
