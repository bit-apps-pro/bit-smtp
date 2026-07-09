import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

describe('toolchain', () => {
  it('renders with RTL + jest-dom', () => {
    render(<button type="button">hello</button>)
    expect(screen.getByRole('button', { name: 'hello' })).toBeInTheDocument()
  })
})
