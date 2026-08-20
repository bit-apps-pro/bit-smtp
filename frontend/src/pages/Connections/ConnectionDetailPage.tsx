import { Link, useNavigate, useParams } from 'react-router-dom'
import { __ } from '@common/helpers/i18nwrap'
import { Flex, Spin, Typography } from 'antd'
import ConnectionEditor from './ConnectionEditor'
import useMailSettings from './data/useMailSettings'
import useProviders from './data/useProviders'

const { Text } = Typography

export default function ConnectionDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { data: settings, isPending: isSettingsPending } = useMailSettings()
  const { data: providers, isPending: isProvidersPending } = useProviders()

  if (isSettingsPending || isProvidersPending) {
    return (
      <Flex justify="center" style={{ padding: 48 }}>
        <Spin size="large" />
      </Flex>
    )
  }

  const connection = settings?.connections.find(item => item.id === id)
  const provider = providers?.find(item => item.key === connection?.provider)

  if (!connection || !provider) {
    return (
      <Flex vertical gap="middle" style={{ padding: 24 }}>
        <Text>{__('Connection not found')}</Text>
        <Link to="/">{__('Back to connections')}</Link>
      </Flex>
    )
  }

  return (
    <Flex vertical gap="middle" style={{ padding: 24 }}>
      <Link to="/">{__('Back to connections')}</Link>
      <ConnectionEditor connection={connection} provider={provider} onSaved={() => navigate('/')} />
    </Flex>
  )
}
