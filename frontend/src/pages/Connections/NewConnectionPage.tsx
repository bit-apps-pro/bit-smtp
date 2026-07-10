import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { __ } from '@common/helpers/i18nwrap'
import { type Connection } from '@pages/Connections/types'
import { Flex, Spin, Typography } from 'antd'
import ConnectionEditor from './ConnectionEditor'
import useProviders from './data/useProviders'

const { Text } = Typography

export default function NewConnectionPage() {
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  const providerKey = searchParams.get('provider')
  const { data: providers, isPending } = useProviders()

  if (isPending) {
    return (
      <Flex justify="center" style={{ padding: 48 }}>
        <Spin size="large" />
      </Flex>
    )
  }

  const provider = providers?.find(item => item.key === providerKey)

  if (!provider) {
    return (
      <Flex vertical gap="middle" style={{ padding: 24 }}>
        <Text>{__('Select a provider to create a connection')}</Text>
        <Link to="/">{__('Back to connections')}</Link>
      </Flex>
    )
  }

  const connection: Connection = {
    id: '',
    provider: provider.key,
    kind: provider.kind,
    name: '',
    enabled: true,
    fromEmail: '',
    fromName: '',
    replyToEmail: '',
    settings: {},
    credentials: {}
  }

  return (
    <Flex vertical gap="middle" style={{ padding: 24 }}>
      <Link to="/">{__('Back to connections')}</Link>
      <ConnectionEditor connection={connection} provider={provider} onSaved={() => navigate('/')} />
    </Flex>
  )
}
