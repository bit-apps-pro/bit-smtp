import { type CSSProperties } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import { Flex, Modal, Typography, theme } from 'antd'
import { type ChannelDefinition, type ChannelKey } from './NotificationChannelFields'
import cls from './NotificationChannelPickerModal.module.css'

const { Text } = Typography

type TileVars = CSSProperties & Record<`--ncp-${string}`, string>

interface NotificationChannelPickerModalProps {
  open: boolean
  channels: ChannelDefinition[]
  onClose: () => void
  onSelect: (key: ChannelKey) => void
}

/** "Add channel" picker: a grid of tiles for the notification channel types not yet added — mirrors ProviderSelectorModal. */
export default function NotificationChannelPickerModal({
  open,
  channels,
  onClose,
  onSelect
}: NotificationChannelPickerModalProps) {
  const { token } = theme.useToken()

  const handleSelect = (key: ChannelKey) => {
    onSelect(key)
    onClose()
  }

  const tileStyle: TileVars = {
    backgroundColor: token.colorBgContainer,
    borderRadius: token.borderRadiusLG,
    padding: token.padding,
    '--ncp-border': token.colorBorderSecondary,
    '--ncp-hover-border': token.colorPrimary,
    '--ncp-hover-shadow': token.boxShadowSecondary,
    '--ncp-focus-color': token.colorPrimary
  }

  return (
    <Modal title={__('Add a channel')} open={open} onCancel={onClose} footer={null} width={480}>
      <div className={cls.grid}>
        {channels.map(channel => {
          const Icon = channel.icon
          return (
            <button
              key={channel.key}
              type="button"
              className={cls.tile}
              style={tileStyle}
              onClick={() => handleSelect(channel.key)}
            >
              <Flex vertical align="center" gap="small">
                <span
                  className={cls.badge}
                  aria-hidden="true"
                  style={{
                    borderRadius: token.borderRadius,
                    backgroundColor: token.colorPrimaryBg,
                    color: token.colorPrimary
                  }}
                >
                  <Icon size={22} strokeWidth={1.75} />
                </span>
                <Text className={cls.label}>{channel.label}</Text>
              </Flex>
            </button>
          )
        })}
      </div>
    </Modal>
  )
}
