import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { HashRouter } from 'react-router-dom'
import '@resource/styles/global.css'
import '@resource/styles/utilities.sass'
import '@resource/styles/wp-css-reset.css'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import 'antd/dist/reset.css'
import { enableMapSet } from 'immer'
import AppRoutes from './AppRoutes'

// to enable immer to work with Map and Set
enableMapSet()

// if (config.IS_DEV) window.appState = {}

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
