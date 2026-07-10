import { Route, Routes } from 'react-router-dom'
import { StyleProvider } from '@ant-design/cssinjs'
import ThemeProvider from '@config/themes/theme.provider'
import ConnectionDetailPage from '@pages/Connections/ConnectionDetailPage'
import ConnectionsListPage from '@pages/Connections/ConnectionsListPage'
import Layout from '@pages/Layout'
import Logs from '@pages/Logs'
import LogDetails from '@pages/Logs/ui/LogDetails'
import MailSendTest from '@pages/MailSendTest/MailSendTest'
import Others from '@pages/Others/Others'
import { message, notification } from 'antd'

export default function AppRoutes() {
  const [, notificationContextHolder] = notification.useNotification()
  const [, messageContextHolder] = message.useMessage()
  notification.config({ placement: 'bottomRight', maxCount: 3 })
  return (
    <ThemeProvider>
      <StyleProvider hashPriority="high">
        {notificationContextHolder}
        {messageContextHolder}
        <Routes>
          <Route path="/" element={<Layout />}>
            <Route index element={<ConnectionsListPage />} />
            <Route path="/connection/:id" element={<ConnectionDetailPage />} />
            <Route path="/test-mail" element={<MailSendTest />} />
            <Route path="/logs" element={<Logs />} />
            <Route path="/logs/:id" element={<LogDetails />} />
            <Route path="/others" element={<Others />} />
            <Route path="*" element={<ConnectionsListPage />} />
          </Route>
        </Routes>
      </StyleProvider>
    </ThemeProvider>
  )
}
