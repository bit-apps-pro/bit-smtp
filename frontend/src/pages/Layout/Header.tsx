import { type CSSProperties, useEffect } from 'react'
import { NavLink, useLocation } from 'react-router-dom'
import { MoonOutlined, SunOutlined } from '@ant-design/icons'
import { __ } from '@common/helpers/i18nwrap'
import useHorizontalOverflow from '@common/hooks/useHorizontalOverflow'
import { IconButton } from '@components/FieldHelp'
import { useTheme } from '@config/themes/theme.provider'
import LogoIcon from '@icons/LogoIcon'
import LogoText from '@icons/LogoText'
import { Layout as AntLayout, Flex, theme } from 'antd'
import cls from './Layout.module.css'

type NavVars = CSSProperties & Record<`--nav-${string}`, string>

/** Primary nav in setup order: connect, route, observe, test, configure. */
function navItems() {
  return [
    { label: __('Connections'), path: '/' },
    { label: __('Routing'), path: '/routing' },
    { label: __('Logs'), path: '/logs' },
    { label: __('Analytics'), path: '/analytics' },
    { label: __('Test email'), path: '/test-mail' },
    { label: __('Settings'), path: '/settings' }
  ]
}

/** Primary nav links; scrolls sideways on narrow screens with edge fades and keeps the current page in view. */
function PrimaryNav() {
  const { pathname } = useLocation()
  const { token } = theme.useToken()
  const { ref, overflowStart, overflowEnd } = useHorizontalOverflow<HTMLElement>()
  const isConnectionSubpage = pathname.startsWith('/connection')

  useEffect(() => {
    ref.current
      ?.querySelector<HTMLElement>(`.${cls.navLinkActive}`)
      ?.scrollIntoView?.({ block: 'nearest', inline: 'nearest' })
  }, [pathname, ref])

  const navStyle: NavVars = {
    '--nav-inactive-color': token.colorTextSecondary,
    '--nav-hover-color': token.colorText,
    '--nav-active-color': token.colorPrimaryText,
    '--nav-underline': `linear-gradient(90deg, ${token.colorPrimary}, ${token.colorInfo})`
  }

  return (
    <nav
      ref={ref}
      className={cls.nav}
      style={navStyle}
      data-overflow-start={overflowStart}
      data-overflow-end={overflowEnd}
      aria-label={__('Bit SMTP sections')}
    >
      {navItems().map(item => (
        <NavLink
          key={item.path}
          to={item.path}
          end={item.path === '/'}
          className={({ isActive }) => {
            const active = item.path === '/' ? isActive || isConnectionSubpage : isActive
            return `${cls.navLink} ${active ? cls.navLinkActive : ''}`.trim()
          }}
        >
          {item.label}
        </NavLink>
      ))}
    </nav>
  )
}

/** Top app header: logo, primary nav and theme toggle; stacks the nav under the logo row on narrow screens. */
export default function Header() {
  const { isDark, toggleTheme } = useTheme()
  const { token } = theme.useToken()

  return (
    <AntLayout.Header
      style={{
        height: 'auto',
        lineHeight: token.lineHeight,
        backgroundColor: token.colorBgContainer,
        paddingInline: token.paddingLG,
        paddingBlock: token.paddingSM
      }}
    >
      <div className={cls.bar} style={{ borderBottom: `${token.colorBorder} 0.5px solid` }}>
        <Flex className={cls.brand} align="center" aria-label="Bit SMTP" role="img">
          <LogoIcon size={44} />
          <LogoText h={44} w={120} />
        </Flex>
        <PrimaryNav />
        <IconButton
          className={cls.themeToggle}
          type="text"
          icon={isDark ? <SunOutlined /> : <MoonOutlined />}
          onClick={toggleTheme}
          label={isDark ? __('Switch to light theme') : __('Switch to dark theme')}
          style={{ fontSize: token.fontSizeLG }}
        />
      </div>
    </AntLayout.Header>
  )
}
