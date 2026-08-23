/** True when two same-shaped values are equal (string arrays compared by content, else strict). */
function fieldEqual(left: unknown, right: unknown): boolean {
  if (Array.isArray(left) && Array.isArray(right)) {
    return left.length === right.length && left.every((value, index) => value === right[index])
  }

  return left === right
}

/** Field-by-field equality for flat form-value objects (string arrays compared by content). */
export default function valuesEqual(a: object, b: object): boolean {
  const left = a as Record<string, unknown>
  const right = b as Record<string, unknown>

  return Object.keys(left).every(key => fieldEqual(left[key], right[key]))
}
