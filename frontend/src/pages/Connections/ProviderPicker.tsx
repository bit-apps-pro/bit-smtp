import { __ } from '@common/helpers/i18nwrap'
import { type ProviderMeta } from '@pages/Connections/types'
import { Select } from 'antd'

export default function ProviderPicker({
  providers,
  value,
  onChange
}: {
  providers: ProviderMeta[]
  value: string
  onChange: (key: string) => void
}) {
  return (
    <Select
      aria-label={__('Provider')}
      style={{ width: '100%' }}
      value={value}
      onChange={key => onChange(key)}
      options={providers.map(provider => ({ value: provider.key, label: provider.label }))}
    />
  )
}
