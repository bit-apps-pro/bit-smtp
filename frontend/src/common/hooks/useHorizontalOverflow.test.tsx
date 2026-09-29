import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import useHorizontalOverflow from './useHorizontalOverflow'

/** Scroll strip that exposes which edges the hook reports as hiding content. */
function Strip() {
  const { ref, overflowStart, overflowEnd } = useHorizontalOverflow<HTMLDivElement>()

  return (
    <div ref={ref} data-testid="strip" data-start={overflowStart} data-end={overflowEnd}>
      content
    </div>
  )
}

/** Gives the strip fixed scroll geometry, since jsdom does no layout. */
function setGeometry(element: HTMLElement, scrollLeft: number) {
  Object.defineProperties(element, {
    scrollWidth: { configurable: true, value: 600 },
    clientWidth: { configurable: true, value: 300 },
    scrollLeft: { configurable: true, writable: true, value: scrollLeft }
  })
}

describe('useHorizontalOverflow', () => {
  it('reports hidden content at each edge as the strip scrolls', () => {
    render(<Strip />)
    const strip = screen.getByTestId('strip')

    setGeometry(strip, 0)
    fireEvent.scroll(strip)
    expect(strip).toHaveAttribute('data-start', 'false')
    expect(strip).toHaveAttribute('data-end', 'true')

    setGeometry(strip, 150)
    fireEvent.scroll(strip)
    expect(strip).toHaveAttribute('data-start', 'true')
    expect(strip).toHaveAttribute('data-end', 'true')

    setGeometry(strip, 300)
    fireEvent.scroll(strip)
    expect(strip).toHaveAttribute('data-start', 'true')
    expect(strip).toHaveAttribute('data-end', 'false')
  })

  it('reports no overflow when everything fits', () => {
    render(<Strip />)

    expect(screen.getByTestId('strip')).toHaveAttribute('data-end', 'false')
  })
})
