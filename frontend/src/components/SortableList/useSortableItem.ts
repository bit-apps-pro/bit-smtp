import { useSortable } from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'

/** Sortable wiring for one list item: its ref and drag style for the wrapper, plus the handle's attributes and listeners. */
export default function useSortableItem(id: string, disabled = false) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id,
    disabled
  })

  return {
    setNodeRef,
    style: {
      transform: CSS.Transform.toString(transform),
      transition,
      opacity: isDragging ? 0.5 : 1
    },
    dragHandleAttributes: attributes,
    dragHandleListeners: listeners
  }
}
