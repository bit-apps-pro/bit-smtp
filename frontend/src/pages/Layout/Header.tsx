/* eslint-disable @typescript-eslint/no-explicit-any */
import { type CSSProperties, useState } from 'react'
import { NavLink } from 'react-router-dom'
import { MoonOutlined, SunOutlined } from '@ant-design/icons'
import { __ } from '@common/helpers/i18nwrap'
import config from '@config/config'
import { useTheme } from '@config/themes/theme.provider'
import LogoIcon from '@icons/LogoIcon'
import LogoText from '@icons/LogoText'
import adBanner from '@resource/img/adBanner.png'
import { Layout as AntLayout, Button, Flex, Modal, Space, Typography, theme } from 'antd'
import confetti from 'canvas-confetti'
import cls from './Layout.module.css'

type NavVars = CSSProperties & Record<`--nav-${string}`, string>

export default function Header() {
  const [isModalOpen, setIsModalOpen] = useState(false)
  const { isDark, toggleTheme } = useTheme()

  const { AD_BUTTON }: { AD_BUTTON: { title: string; campaign: string; alt?: string; url: string } } =
    config
  const { Text } = Typography

  const { useToken } = theme
  const { token } = useToken()

  const navItems = [
    { label: __('Configuration'), path: '/' },
    { label: __('Routing'), path: '/routing' },
    { label: __('Test'), path: '/test-mail' },
    { label: __('Logs'), path: '/logs' },
    { label: __('Others'), path: '/others' }
  ]

  const navStyle: NavVars = {
    '--nav-inactive-color': token.colorTextSecondary,
    '--nav-hover-color': token.colorText,
    '--nav-active-color': token.colorPrimary,
    '--nav-underline': `linear-gradient(90deg, ${token.colorPrimary}, ${token.colorInfo})`
  }

  const handleConfetti = () => {
    confetti({
      particleCount: 100,
      spread: 70,
      origin: { y: 0.6 },
      zIndex: 1000
    })
  }
  const showModal = () => {
    setIsModalOpen(true)
    handleConfetti()
  }
  const handleOk = () => setIsModalOpen(false)
  const handleCancel = () => setIsModalOpen(false)

  return (
    <>
      <AntLayout.Header
        style={{
          height: 'min-content',
          backgroundColor: token.colorBgContainer,
          paddingInline: token.paddingLG,
          paddingBlock: token.paddingSM
        }}
      >
        <Flex
          align="center"
          justify="space-between"
          wrap
          gap="middle"
          style={{ width: '100%', borderBottom: `${token.colorBorder} 0.5px solid` }}
        >
          <Flex align="center">
            <LogoIcon size={44} />
            <LogoText h={44} w={120} />
          </Flex>
          <nav className={cls.nav} style={navStyle}>
            {navItems.map(item => (
              <NavLink
                key={item.path}
                to={item.path}
                end={item.path === '/'}
                className={({ isActive }) =>
                  `${cls.navLink} ${isActive ? cls.navLinkActive : ''}`.trim()
                }
              >
                {item.label}
              </NavLink>
            ))}
          </nav>
          <Space align="center" size="small">
            <Text type="secondary" style={{ fontSize: token.fontSizeSM }}>
              {__('Share Your Product Experience!')}
            </Text>
            <a
              href="https://wordpress.org/support/plugin/bit-smtp/reviews/"
              target="_blank"
              rel="noreferrer"
              className={cls.reviewLink}
              style={{ color: token.colorPrimary, fontWeight: token.fontWeightStrong }}
            >
              {__('Review us')}
            </a>
            <Button
              type="text"
              icon={isDark ? <SunOutlined /> : <MoonOutlined />}
              onClick={toggleTheme}
              aria-label={isDark ? __('Switch to light theme') : __('Switch to dark theme')}
              style={{ fontSize: 16 }}
            />
          </Space>
        </Flex>
      </AntLayout.Header>
      {AD_BUTTON && AD_BUTTON.title && (
        <div
          className={cls.bitSmtpAd}
          style={{
            position: 'fixed',
            top: 35,
            left: '60%',
            zIndex: 1001,
            boxShadow: '0 2px 8px rgba(0,0,0,0.15)'
          }}
        >
          <button type="button" onClick={showModal} className={cls.btn}>
            {AD_BUTTON.title}
            <span className={cls.star} />
            <span className={cls.star} />
            <span className={cls.star} />
            <span className={cls.star} />
          </button>
        </div>
      )}
      <Modal
        open={isModalOpen}
        onOk={handleOk}
        onCancel={handleCancel}
        footer={null}
        centered
        width="40vw"
      >
        <a
          href={`${AD_BUTTON.url}/?utm_source=bit-smtp&utm_medium=inside-plugin&utm_campaign=${AD_BUTTON.campaign}`}
          target="_blank"
          rel="noreferrer"
        >
          <img src={adBanner} alt={AD_BUTTON.alt || AD_BUTTON.title} width="100%" />
        </a>
      </Modal>
    </>
  )
}
