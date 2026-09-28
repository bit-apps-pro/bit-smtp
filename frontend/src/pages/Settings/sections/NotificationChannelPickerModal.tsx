import { type CSSProperties } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import { type AlertChannelType } from '@pages/Connections/types'
import { Flex, Modal, Typography, theme } from 'antd'
import { type ChannelDefinition } from './NotificationChannelFields'
import cls from './NotificationChannelPickerModal.module.css'

const { Text } = Typography

type TileVars = CSSProperties & Record<`--ncp-${string}`, string>

interface NotificationChannelPickerModalProps {
  open: boolean
  channels: ChannelDefinition[]
  onClose: () => void
  onSelect: (type: AlertChannelType) => void
}

/** "Add channel" picker: a grid of tiles, one per notification channel type — mirrors ProviderSelectorModal. */
export default function NotificationChannelPickerModal({
  open,
  channels,
  onClose,
  onSelect
}: NotificationChannelPickerModalProps) {
  const { token } = theme.useToken()

  const handleSelect = (type: AlertChannelType) => {
    onSelect(type)
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
              key={channel.type}
              type="button"
              className={cls.tile}
              style={tileStyle}
              onClick={() => handleSelect(channel.type)}
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
