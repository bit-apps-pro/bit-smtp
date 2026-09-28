import type React from 'react'
import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import { type ThemeConfig } from 'antd'
import { ConfigProvider, theme } from 'antd'
import darkTheme from './theme.dark'
import lightTheme from './theme.light'

type ThemeContextType = {
  isDark: boolean
  toggleTheme: () => void
}

const defaultContext: ThemeContextType = {
  isDark: false,
  toggleTheme: () => {}
}

const THEME_STORAGE_KEY = 'bit_smtp_theme'

const ThemeContext = createContext<ThemeContextType>(defaultContext)

/** Save the theme mode, returning false when storage is unavailable (private mode, blocked site data). */
function persistThemeMode(mode: 'dark' | 'light'): boolean {
  try {
    localStorage.setItem(THEME_STORAGE_KEY, mode)
    return true
  } catch {
    return false
  }
}

/** Access the current theme context (isDark, toggleTheme). */
export const useTheme = () => useContext(ThemeContext)

/** Provides the antd theme (light/dark) to descendants, persisting the user's choice to localStorage. */
export function ThemeProvider({ children }: { children: React.ReactNode }) {
  const [isDark, setIsDark] = useState<boolean>(() => {
    try {
      const v = localStorage.getItem(THEME_STORAGE_KEY)
      return v === 'dark'
    } catch {
      return false
    }
  })

  useEffect(() => {
    const mode = isDark ? 'dark' : 'light'
    document.documentElement.setAttribute('data-theme', mode)
    persistThemeMode(mode)
  }, [isDark])

  /** Flip between light and dark. */
  const toggleTheme = useCallback(() => setIsDark(prev => !prev), [])

  const appliedTheme = isDark ? darkTheme : lightTheme

  const contextValue = useMemo(() => ({ isDark, toggleTheme }), [isDark, toggleTheme])

  return (
    <ThemeContext.Provider value={contextValue}>
      <ConfigProvider
        theme={{
          ...(appliedTheme as ThemeConfig),
          algorithm: isDark ? theme.darkAlgorithm : theme.defaultAlgorithm
        }}
      >
        {children}
      </ConfigProvider>
    </ThemeContext.Provider>
  )
}

export default ThemeProvider
