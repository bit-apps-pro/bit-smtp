/* eslint-disable @typescript-eslint/no-explicit-any */
import { useState } from 'react'
import { NavLink, useLocation } from 'react-router-dom'
import { MoonOutlined, SunOutlined } from '@ant-design/icons'
import { __ } from '@common/helpers/i18nwrap'
import config from '@config/config'
import { useTheme } from '@config/themes/theme.provider'
import LogoIcon from '@icons/LogoIcon'
import LogoText from '@icons/LogoText'
import adBanner from '@resource/img/adBanner.png'
import { Layout as AntLayout, Button, Flex, Menu, Modal, Space, Typography, theme } from 'antd'
import confetti from 'canvas-confetti'
import cls from './Layout.module.css'

export default function Header() {
  const [isModalOpen, setIsModalOpen] = useState(false)
  const { isDark, toggleTheme } = useTheme()
  const location = useLocation()

  const { AD_BUTTON }: { AD_BUTTON: { title: string; campaign: string; alt?: string; url: string } } =
    config
  const { Text } = Typography

  const { useToken } = theme
  const antConfig = useToken()

  const navItems = [
    { label: __('Configuration'), path: '/' },
    { label: __('Routing'), path: '/routing' },
    { label: __('Test'), path: '/test-mail' },
    { label: __('Logs'), path: '/logs' },
    { label: __('Others'), path: '/others' }
  ]

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
        style={{ height: 'min-content', backgroundColor: antConfig.token.colorBgContainer }}
      >
        <Flex
          align="center"
          justify="space-between"
          wrap
          style={{ width: '100%', borderBottom: `${antConfig.token.colorBorder} 0.5px solid` }}
        >
          <Flex align="center">
            <LogoIcon size={44} />
            <LogoText h={44} w={120} />
          </Flex>
          <Space align="center" size="large">
            <Menu
              mode="horizontal"
              disabledOverflow
              selectedKeys={[
                navItems.find(item =>
                  item.path === '/'
                    ? item.path === location.pathname
                    : location.pathname.includes(item.path)
                )?.path || '/'
              ]}
              items={navItems.map(item => ({
                key: item.path,
                label: (
                  <NavLink to={item.path} className="link">
                    {item.label}
                  </NavLink>
                )
              }))}
              style={{ border: 'none', background: 'none', fontWeight: 500 }}
            />
          </Space>
          <Space align="center" size="middle">
            <Text style={{ fontSize: 14 }}>Share Your Product Experience!</Text>
            <a
              href="https://wordpress.org/support/plugin/bit-smtp/reviews/"
              target="_blank"
              rel="noreferrer"
            >
              Review us
            </a>
            <Button
              type="text"
              icon={isDark ? <SunOutlined /> : <MoonOutlined />}
              onClick={toggleTheme}
              style={{ fontSize: 18 }}
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
