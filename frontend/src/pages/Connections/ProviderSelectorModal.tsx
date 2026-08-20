import { type CSSProperties } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import { Flex, Modal, Spin, Tag, Typography, theme } from 'antd'
import cls from './ProviderSelectorModal.module.css'
import useProviders from './data/useProviders'
import { getProviderVisual } from './providerVisuals'

const { Text } = Typography

type TileVars = CSSProperties & Record<`--psm-${string}`, string>

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

  const tileStyle: TileVars = {
    backgroundColor: token.colorBgContainer,
    borderRadius: token.borderRadiusLG,
    padding: token.padding,
    '--psm-border': token.colorBorderSecondary,
    '--psm-hover-border': token.colorPrimary,
    '--psm-hover-shadow': token.boxShadowSecondary,
    '--psm-focus-color': token.colorPrimary
  }

  return (
    <Modal title={__('Choose a provider')} open={open} onCancel={onClose} footer={null} width={640}>
      {isPending ? (
        <Flex justify="center" style={{ padding: 24 }}>
          <Spin size="large" />
        </Flex>
      ) : (
        <div className={cls.grid}>
          {providers?.map(provider => {
            const visual = getProviderVisual(provider.key, provider.label)
            return (
              <button
                key={provider.key}
                type="button"
                className={cls.tile}
                style={tileStyle}
                onClick={() => handleSelect(provider.key)}
              >
                <Flex vertical align="center" gap="small">
                  <span className={cls.badge} aria-hidden="true">
                    {visual.logo ? (
                      <img src={visual.logo} alt={provider.label} className={cls.logo} />
                    ) : (
                      <span className={cls.letterBadge} style={{ backgroundColor: visual.accent }}>
                        {visual.initial}
                      </span>
                    )}
                  </span>
                  <Text className={cls.label}>{provider.label}</Text>
                  <span className={cls.meta} aria-hidden="true">
                    <Text type="secondary" className={cls.blurb}>
                      {visual.blurb}
                    </Text>
                    <Tag>{provider.kind}</Tag>
                  </span>
                </Flex>
              </button>
            )
          })}
        </div>
      )}
    </Modal>
  )
}
