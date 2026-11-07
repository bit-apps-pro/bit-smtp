import { type ReactNode } from 'react'
import {
  DndContext,
  type DragEndEvent,
  KeyboardSensor,
  PointerSensor,
  closestCenter,
  useSensor,
  useSensors
} from '@dnd-kit/core'
import { SortableContext, type SortingStrategy, sortableKeyboardCoordinates } from '@dnd-kit/sortable'

/** Drag-and-keyboard sortable container: pointer and keyboard sensors around a sortable context for `items`. */
export default function SortableList({
  items,
  strategy,
  onDragEnd,
  children
}: {
  items: string[]
  strategy: SortingStrategy
  onDragEnd: (event: DragEndEvent) => void
  children: ReactNode
}) {
  const sensors = useSensors(
    useSensor(PointerSensor),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates })
  )

  return (
    <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
      <SortableContext items={items} strategy={strategy}>
        {children}
      </SortableContext>
    </DndContext>
  )
}
