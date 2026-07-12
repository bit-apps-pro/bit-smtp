/* eslint-disable @typescript-eslint/no-explicit-any */
import { Outlet } from 'react-router-dom'
import { Layout as AntLayout, theme } from 'antd'
import Header from './Header'

function Layout() {
  const { useToken } = theme
  const antConfig = useToken()
  return (
    <AntLayout
      style={{
        minHeight: '100vh',
        backgroundColor: antConfig.token.colorBgContainer,
        borderRadius: antConfig.token.borderRadius,
        border: `1px solid ${antConfig.token.controlOutline}`
      }}
    >
      <Header />
      <Outlet />
    </AntLayout>
  )
}

export default Layout
