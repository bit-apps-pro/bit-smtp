/* eslint-disable @typescript-eslint/no-explicit-any */
import { Outlet } from 'react-router-dom'
import { Layout as AntLayout, theme } from 'antd'
import Header from './Header'
import useWpSubmenuHighlight from './useWpSubmenuHighlight'

const CONTENT_MAX_WIDTH = 1280

/** App shell: header plus a max-width content outlet for the routed page. */
function Layout() {
  const { useToken } = theme
  const { token } = useToken()
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
      <AntLayout.Content style={{ maxWidth: CONTENT_MAX_WIDTH, width: '100%', margin: '0 auto' }}>
        <Outlet />
      </AntLayout.Content>
    </AntLayout>
  )
}

export default Layout
