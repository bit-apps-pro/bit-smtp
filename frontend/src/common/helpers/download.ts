/** Trigger a browser download of a Blob under the given filename (object-URL + detached anchor click). */
// eslint-disable-next-line import/prefer-default-export -- shared named helper, imported by name across views
export function triggerBlobDownload(filename: string, blob: Blob): void {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.click()
  URL.revokeObjectURL(url)
}
