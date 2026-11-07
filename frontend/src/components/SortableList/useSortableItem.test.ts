import { renderHook } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import useSortableItem from './useSortableItem'

const { useSortableMock } = vi.hoisted(() => ({
  useSortableMock: vi.fn(() => ({
    attributes: { role: 'button' },
    listeners: { onKeyDown: vi.fn() },
    setNodeRef: vi.fn(),
    transform: null,
    transition: undefined,
    isDragging: false
  }))
}))
vi.mock('@dnd-kit/sortable', () => ({ useSortable: useSortableMock }))

describe('useSortableItem', () => {
  beforeEach(() => useSortableMock.mockClear())

  it('passes the id and disabled flag through to useSortable', () => {
    renderHook(() => useSortableItem('conn_1', true))

    expect(useSortableMock).toHaveBeenCalledWith({ id: 'conn_1', disabled: true })
  })

  it('leaves the item enabled by default', () => {
    renderHook(() => useSortableItem('rule_1'))

    expect(useSortableMock).toHaveBeenCalledWith({ id: 'rule_1', disabled: false })
  })

  it('dims the item while it is being dragged', () => {
    useSortableMock.mockReturnValueOnce({ ...useSortableMock(), isDragging: true })
    const { result } = renderHook(() => useSortableItem('rule_1'))

    expect(result.current.style.opacity).toBe(0.5)
  })
})
