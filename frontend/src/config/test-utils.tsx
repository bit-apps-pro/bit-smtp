/* Test-only helper: RTL is a devDependency and a single named export is intentional here. */

/* eslint-disable import/no-extraneous-dependencies, import/prefer-default-export */
import { type ReactElement } from 'react'
import { HashRouter } from 'react-router-dom'
import { StyleProvider } from '@ant-design/cssinjs'
import ThemeProvider from '@config/themes/theme.provider'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render } from '@testing-library/react'

/** Render a component inside the same provider stack the app uses (query, router, theme). */
export function renderWithProviders(ui: ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={client}>
      <ThemeProvider>
        <StyleProvider hashPriority="high">
          <HashRouter>{ui}</HashRouter>
        </StyleProvider>
      </ThemeProvider>
    </QueryClientProvider>
  )
}
