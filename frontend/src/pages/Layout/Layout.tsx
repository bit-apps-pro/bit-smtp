import { Link, Outlet, useLocation } from 'react-router-dom'
import { __ } from '@common/helpers/i18nwrap'
import { OnboardingChecklist } from '@components/Onboarding'
import { Layout as AntLayout, Flex, Typography, theme } from 'antd'
import Header from './Header'
import useWpSubmenuHighlight from './useWpSubmenuHighlight'

const CONTENT_MAX_WIDTH = 1280

/** Secondary links kept out of the primary nav. */
function Footer() {
  const { token } = theme.useToken()
  return (
    <Flex
      component="footer"
      justify="center"
      gap="middle"
      wrap
      style={{ padding: token.paddingLG, borderTop: `1px solid ${token.colorBorderSecondary}` }}
    >
      <Typography.Text type="secondary">Bit SMTP</Typography.Text>
      <Link to="/others">{__('More plugins from Bit Apps')}</Link>
    </Flex>
  )
}

/** App shell: header, first-run checklist on Connections, the routed page, and a footer. */
function Layout() {
  const { token } = theme.useToken()
  const { pathname } = useLocation()
  useWpSubmenuHighlight()
  return (
    <AntLayout
      style={{
        minHeight: '100vh',
        backgroundColor: token.colorBgLayout,
        borderRadius: token.borderRadiusLG,
        border: `1px solid ${token.colorBorderSecondary}`
      }}
    >
      <div
        style={{
          borderRadius: `${token.borderRadiusLG}px ${token.borderRadiusLG}px 0 0`,
          overflow: 'hidden'
        }}
      >
        <Header />
      </div>
      <div
        role="region"
        aria-label="Bit SMTP"
        style={{ maxWidth: CONTENT_MAX_WIDTH, width: '100%', margin: '0 auto', flex: 'auto' }}
      >
        {pathname === '/' ? <OnboardingChecklist /> : null}
        <Outlet />
      </div>
      <Footer />
    </AntLayout>
  )
}

export default Layout
