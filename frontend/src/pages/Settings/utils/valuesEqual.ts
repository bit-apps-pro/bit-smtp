/** True for a non-null, non-array object whose keys can be compared field by field. */
function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

/** Deep structural equality for JSON-like form values (arrays by position, objects by every key). */
function fieldEqual(left: unknown, right: unknown): boolean {
  if (Array.isArray(left) && Array.isArray(right)) {
    return left.length === right.length && left.every((value, index) => fieldEqual(value, right[index]))
  }
  if (isRecord(left) && isRecord(right)) {
    const keys = new Set([...Object.keys(left), ...Object.keys(right)])
    return [...keys].every(key => fieldEqual(left[key], right[key]))
  }

  return left === right
}

/** Equality of a live form snapshot against its loaded values, checking only the snapshot's own keys. */
export default function valuesEqual(a: object, b: object): boolean {
  const left = a as Record<string, unknown>
  const right = b as Record<string, unknown>

  return Object.keys(left).every(key => fieldEqual(left[key], right[key]))
}
