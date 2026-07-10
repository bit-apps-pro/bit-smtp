import { __ } from '@common/helpers/i18nwrap'
import { Button, Card, Flex, List, Modal, Spin, Tag, theme } from 'antd'
import useProviders from './data/useProviders'

export default function ProviderSelectorModal({
  open,
  onClose,
  onSelect
}: {
  open: boolean
  onClose: () => void
  onSelect: (providerKey: string) => void
}) {
  const { data: providers, isPending } = useProviders()
  const { token } = theme.useToken()

  const handleSelect = (providerKey: string) => {
    onSelect(providerKey)
    onClose()
  }

  return (
    <Modal title={__('Choose a provider')} open={open} onCancel={onClose} footer={null}>
      {isPending ? (
        <Flex justify="center" style={{ padding: 24 }}>
          <Spin size="large" />
        </Flex>
      ) : (
        <List
          dataSource={providers}
          renderItem={provider => (
            <List.Item style={{ padding: 0, marginBottom: token.marginSM, border: 'none' }}>
              <Card size="small" style={{ width: '100%' }}>
                <Flex justify="space-between" align="center" gap="small">
                  <Button
                    type="link"
                    style={{ padding: 0, height: 'auto' }}
                    onClick={() => handleSelect(provider.key)}
                  >
                    {provider.label}
                  </Button>
                  <Tag>{provider.kind}</Tag>
                </Flex>
              </Card>
            </List.Item>
          )}
        />
      )}
    </Modal>
  )
}
