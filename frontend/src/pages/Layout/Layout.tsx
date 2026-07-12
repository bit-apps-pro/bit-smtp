/* eslint-disable @typescript-eslint/no-explicit-any */
import { Outlet } from 'react-router-dom'
import { Layout as AntLayout, theme } from 'antd'
import Header from './Header'

const CONTENT_MAX_WIDTH = 1280

function Layout() {
  const { useToken } = theme
  const { token } = useToken()
  return (
    <AntLayout
      style={{
        minHeight: '100vh',
        backgroundColor: token.colorBgLayout,
        borderRadius: token.borderRadiusLG,
        border: `1px solid ${token.colorBorderSecondary}`,
        overflow: 'hidden'
      }}
    >
      <Header />
      <AntLayout.Content style={{ maxWidth: CONTENT_MAX_WIDTH, width: '100%', margin: '0 auto' }}>
        <Outlet />
      </AntLayout.Content>
    </AntLayout>
  )
}

export default Layout
