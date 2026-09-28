import { render, screen } from '@testing-library/react'
import { ScrollText } from 'lucide-react'
import { describe, expect, it } from 'vitest'
import TabLabel, { StatusDot } from './TabLabel'

describe('StatusDot', () => {
  it('uses the custom activeColor when active', () => {
    const { container } = render(<StatusDot active activeColor="rgb(255, 0, 0)" />)

    expect((container.firstChild as HTMLElement).style.backgroundColor).toBe('rgb(255, 0, 0)')
  })

  it('falls back to the success token color when active without an override', () => {
    const { container: withDefault } = render(<StatusDot active />)

    const defaultColor = (withDefault.firstChild as HTMLElement).style.backgroundColor
    expect(defaultColor).not.toBe('')
    expect(defaultColor).not.toBe('rgb(255, 0, 0)')
  })

  it('ignores activeColor when inactive', () => {
    const { container: withoutOverride } = render(<StatusDot active={false} />)
    const { container: withOverride } = render(<StatusDot active={false} activeColor="rgb(255, 0, 0)" />)

    const colorWithoutOverride = (withoutOverride.firstChild as HTMLElement).style.backgroundColor
    const colorWithOverride = (withOverride.firstChild as HTMLElement).style.backgroundColor
    expect(colorWithOverride).toBe(colorWithoutOverride)
    expect(colorWithOverride).not.toBe('rgb(255, 0, 0)')
  })
})

describe('TabLabel', () => {
  it('renders the icon and label with no Off hint when enabled is omitted', () => {
    render(<TabLabel icon={ScrollText} label="General" />)

    expect(screen.getByText('General')).toBeInTheDocument()
    expect(screen.queryByText('Off')).not.toBeInTheDocument()
  })

  it('renders no Off hint when the section is enabled', () => {
    render(<TabLabel icon={ScrollText} label="General" enabled />)

    expect(screen.queryByText('Off')).not.toBeInTheDocument()
  })

  it('renders an Off hint when the section is disabled', () => {
    render(<TabLabel icon={ScrollText} label="General" enabled={false} />)

    expect(screen.getByText('Off')).toBeInTheDocument()
  })
})
