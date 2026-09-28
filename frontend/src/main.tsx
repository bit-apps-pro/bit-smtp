import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { HashRouter } from 'react-router-dom'
import '@ant-design/v5-patch-for-react-19'
import '@resource/styles/global.css'
import '@resource/styles/utilities.sass'
import '@resource/styles/wp-css-reset.css'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import 'antd/dist/reset.css'
import { enableMapSet } from 'immer'
import AppRoutes from './AppRoutes'

enableMapSet()

const elm = document.getElementById('bit-apps-root')

const queryClient = new QueryClient()

if (elm) {
  const root = createRoot(elm)

  root.render(
    <StrictMode>
      <QueryClientProvider client={queryClient}>
        <HashRouter>
          <AppRoutes />
        </HashRouter>
      </QueryClientProvider>
    </StrictMode>
  )
}
