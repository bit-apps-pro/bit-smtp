import { NavLink, useLocation } from 'react-router-dom'
import { DownOutlined, MoonOutlined, SunOutlined } from '@ant-design/icons'
import { __ } from '@common/helpers/i18nwrap'
import { IconButton } from '@components/FieldHelp'
import { useTheme } from '@config/themes/theme.provider'
import LogoIcon from '@icons/LogoIcon'
import LogoText from '@icons/LogoText'
import { Layout as AntLayout, Flex, Menu, theme } from 'antd'
import cls from './Layout.module.css'

const ROOT_PATH = '/'

/** Primary nav in setup order: connect, route, observe, test, configure. */
function navItems() {
  return [
    { label: __('Connections'), path: ROOT_PATH },
    { label: __('Routing'), path: '/routing' },
    { label: __('Logs'), path: '/logs' },
    { label: __('Analytics'), path: '/analytics' },
    { label: __('Test email'), path: '/test-mail' },
    { label: __('Settings'), path: '/settings' }
  ]
}

/** Nav path owning the current route: section subpages light their parent, connection subpages light Connections. */
function activeNavPath(pathname: string): string {
  if (pathname === ROOT_PATH || pathname.startsWith('/connection')) {
    return ROOT_PATH
  }

  const match = navItems().find(
    item => item.path !== ROOT_PATH && (pathname === item.path || pathname.startsWith(`${item.path}/`))
  )
  return match?.path ?? ''
}

/** Primary nav; links that don't fit the header width collapse into a "More" menu instead of scrolling. */
function PrimaryNav() {
  const { pathname } = useLocation()

  return (
    <nav className={cls.nav} aria-label={__('Bit SMTP sections')}>
      <Menu
        mode="horizontal"
        className={cls.navMenu}
        selectedKeys={[activeNavPath(pathname)]}
        overflowedIndicator={
          <span>
            {__('More')} <DownOutlined />
          </span>
        }
        items={navItems().map(item => ({
          key: item.path,
          label: (
            <NavLink to={item.path} end={item.path === ROOT_PATH}>
              {item.label}
            </NavLink>
          )
        }))}
      />
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
        <Flex className={cls.brand} align="center" aria-label="Bit SMTP" role="img" gap={6}>
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
